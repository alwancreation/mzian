<?php

declare(strict_types=1);

namespace App\Agent\Agents;

use App\Agent\AgentContext;
use App\Agent\AgentInterface;
use App\Agent\AgentPermission;
use App\Agent\AgentResult;
use App\Agent\Exception\AgentException;
use App\Delivery\CredentialService;
use App\Deployment\Dto\DeploymentRequest;
use App\Deployment\Entity\Deployment;
use App\Deployment\Enum\DeploymentStatus;
use App\Deployment\Provider\DeploymentProviderInterface;
use App\Hosting\Entity\HostingAccount;
use App\Project\Enum\PipelineStep;
use App\Provider\Enum\ProviderType;
use App\Provider\Enum\ProvisioningMethod;
use App\Provider\ProviderRegistry;
use App\Shared\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Deployment Agent: puts the TESTED version online (one release per version,
 * never twice), with its runtime configuration (administrator login hash, never
 * the password itself), then records the URLs.
 */
final readonly class DeploymentAgent implements AgentInterface
{
    public function __construct(
        private ProviderRegistry $providers,
        #[AutowireLocator('mzian.deployment_provider', defaultIndexMethod: 'getDriver')]
        private ContainerInterface $drivers,
        private CredentialService $credentials,
        private SettingsService $settings,
        private EntityManagerInterface $em,
    ) {
    }

    public static function getCode(): string
    {
        return 'deployment';
    }

    public static function step(): PipelineStep
    {
        return PipelineStep::Deployment;
    }

    public static function requiredPermissions(string $operation): array
    {
        return [AgentPermission::DEPLOYMENT_DEPLOY, AgentPermission::CREDENTIALS_HASH];
    }

    public function execute(AgentContext $context): AgentResult
    {
        $project = $context->project;
        $development = $context->outputOf(PipelineStep::Development);
        $testing = $context->outputOf(PipelineStep::Testing);
        if ('passed' !== ($testing['report']['status'] ?? null)) {
            throw AgentException::permanent('Refusing to deploy a version whose automated tests did not pass.');
        }
        $version = (string) ($development['version'] ?? '');
        $directory = (string) ($development['build_dir'] ?? '');

        $provider = $this->providers->resolve(ProviderType::Deployment, $project);
        if (!$this->drivers->has($provider->getDriver())) {
            throw AgentException::permanent(\sprintf('No deployment driver "%s" installed.', $provider->getDriver()));
        }
        /** @var DeploymentProviderInterface $driver */
        $driver = $this->drivers->get($provider->getDriver());

        $key = $context->idempotencyKey('deploy-'.$version);
        $deployment = $this->em->getRepository(Deployment::class)->findOneBy(['idempotencyKey' => $key]);
        if (null === $deployment) {
            $deployment = new Deployment($project, $provider->getCode(), 'production', $version, $development['commit'] ?? null, $key, ProvisioningMethod::Mock === $provider->getProvisioningMethod());
            $this->em->persist($deployment);
        }
        $admin = $this->credentials->find($project, 'app_admin') ?? throw AgentException::permanent('The administrator account of the application is missing.');
        $generation = $this->settings->get('generation');
        $deployment->start();
        $result = $driver->deploy($provider, new DeploymentRequest(
            $project->getSlug(),
            $directory,
            $version,
            $project->getDomainName(),
            $key,
            $this->em->getRepository(HostingAccount::class)->findOneBy(['project' => $project]),
            [
                'admin_email' => $admin->getUsername(),
                'admin_password_hash' => $this->credentials->passwordHash($admin),
                'notify_email' => $project->getCustomer()?->getEmail() ?? '',
                'timezone' => (string) ($generation['timezone'] ?? 'UTC'),
            ],
        ));
        foreach ($result->logs as $line) {
            $deployment->log($line);
        }
        $deployment->succeed($result->url, $result->internalUrl, $result->externalId);
        $project->setDeploymentUrl($result->url);
        $project->setAdminUrl($result->adminUrl);
        if ($result->simulated) {
            $project->markSimulated();
        }
        $this->em->flush();
        $context->log('Deployed', ['url' => $result->url, 'version' => $version, 'simulated' => $result->simulated]);

        return new AgentResult('Version '.$version.' online: '.$result->url, [
            'deployment' => $deployment->getId(),
            'url' => $result->url,
            'internal_url' => $result->internalUrl,
            'admin_url' => $result->adminUrl,
            'version' => $version,
            'simulated' => $result->simulated,
            'status' => DeploymentStatus::Succeeded->value,
        ]);
    }
}
