<?php

declare(strict_types=1);

namespace App\Agent\Agents;

use App\Agent\AgentContext;
use App\Agent\AgentInterface;
use App\Agent\AgentPermission;
use App\Agent\AgentResult;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\SubscriptionRepository;
use App\Delivery\CredentialService;
use App\Lead\Entity\LeadActivity;
use App\Lead\Service\LeadService;
use App\Project\Enum\PipelineStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Delivery Agent: delivery documentation in the customer's language (URLs, where
 * to find the credentials, features, what is left to configure, simulation
 * notice), activates the subscription and closes the project. The customer is
 * notified by the state machine when the project is completed.
 */
final readonly class DeliveryAgent implements AgentInterface
{
    public function __construct(
        private CredentialService $credentials,
        private SubscriptionRepository $subscriptions,
        private LeadService $leads,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
    ) {
    }

    public static function getCode(): string
    {
        return 'delivery';
    }

    public static function step(): PipelineStep
    {
        return PipelineStep::Delivery;
    }

    public static function requiredPermissions(string $operation): array
    {
        return [AgentPermission::DELIVERY_SEND];
    }

    public function execute(AgentContext $context): AgentResult
    {
        $project = $context->project;
        $locale = $project->getLocale();
        $t = fn (string $key, array $parameters = []) => $this->translator->trans('delivery.'.$key, $parameters, 'messages', $locale);
        $deployment = $context->outputOf(PipelineStep::Deployment);
        $development = $context->outputOf(PipelineStep::Development);
        $domain = $context->outputOf(PipelineStep::Domain);
        $coverage = (array) ($development['coverage'] ?? []);

        $lines = [$t('title', ['%project%' => $project->getName()]), ''];
        $lines[] = $t('website', ['%url%' => (string) ($deployment['url'] ?? '—')]);
        $lines[] = $t('admin', ['%url%' => (string) ($deployment['admin_url'] ?? '—')]);
        $admin = $this->credentials->find($project, 'app_admin');
        if (null !== $admin) {
            $lines[] = $t('login', ['%username%' => $admin->getUsername()]);
        }
        $lines[] = $t('credentials');
        if (!empty($domain['domain'])) {
            $lines[] = '';
            $lines[] = $t('domain', ['%domain%' => (string) $domain['domain']]);
            if (true === ($domain['dns_by_customer'] ?? false)) {
                $lines[] = $t('dns_instructions');
                foreach ((array) ($domain['dns_records'] ?? []) as $record) {
                    $lines[] = \sprintf('  %s  %s  →  %s', $record['type'], $record['name'], $record['value']);
                }
            }
        }
        if ([] !== (array) ($coverage['implemented'] ?? [])) {
            $lines[] = '';
            $lines[] = $t('features');
            foreach ((array) $coverage['implemented'] as $feature) {
                $lines[] = '  ✓ '.($project->getSolution()?->getFeature((string) $feature)?->getName($locale) ?? str_replace('_', ' ', (string) $feature));
            }
        }
        foreach ((array) ($coverage['configuration'] ?? []) as $feature => $note) {
            $lines[] = '  ⚙ '.$t('to_configure', ['%feature%' => str_replace('_', ' ', (string) $feature)]);
        }
        foreach ((array) ($coverage['custom'] ?? []) as $feature) {
            $lines[] = '  🛠 '.$t('custom', ['%feature%' => str_replace('_', ' ', (string) $feature)]);
        }
        if ($project->isSimulated()) {
            $lines[] = '';
            $lines[] = $t('simulated');
        }
        $lines[] = '';
        $lines[] = $t('support');
        $project->setDeliveryDocumentation(implode("\n", $lines));

        foreach ($this->subscriptions->findBy(['project' => $project, 'status' => SubscriptionStatus::Pending]) as $subscription) {
            $subscription->activate();
            $context->log('Subscription activated', ['plan' => $subscription->getPlan()->getCode()]);
        }
        $project->markDelivered();
        $project->markCompleted();
        if (null !== $project->getLead()) {
            $this->leads->track($project->getLead(), LeadActivity::PROJECT_DELIVERED, 'Project delivered', ['project' => $project->getReference(), 'url' => $deployment['url'] ?? null]);
        }
        $this->em->flush();

        return new AgentResult('Delivered: '.($deployment['url'] ?? ''), ['delivered_at' => $project->getDeliveredAt()?->format(\DATE_ATOM), 'url' => $deployment['url'] ?? null]);
    }
}
