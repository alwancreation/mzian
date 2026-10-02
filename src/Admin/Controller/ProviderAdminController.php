<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Provider\CredentialVault;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\ProviderSettingsValidator;
use App\Provider\Repository\ProviderRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Controller\CsrfGuardTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin > Providers: which implementation handles hosting, domains, payments,
 * AI, repositories, deployments and notifications.
 *
 * Credentials are write-only: they are encrypted as soon as they are submitted
 * and never displayed, logged or returned again (only "stored / from env / missing").
 * Changing anything is reserved to super administrators.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/providers', defaults: ['_locale' => 'en'])]
final class ProviderAdminController extends AbstractController
{
    use CsrfGuardTrait;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'admin_providers', methods: ['GET'])]
    public function index(): Response
    {
        $groups = [];
        foreach (ProviderType::cases() as $type) {
            $groups[$type->value] = [];
        }
        foreach ($this->providers->findBy([], ['type' => 'ASC', 'isDefault' => 'DESC', 'priority' => 'DESC', 'id' => 'ASC']) as $provider) {
            $groups[$provider->getType()->value][] = ['provider' => $provider, 'credentials' => $this->credentialStatus($provider), 'settings' => json_encode($provider->getSettings() ?: new \stdClass(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)];
        }

        return $this->render('admin/providers/index.html.twig', ['groups' => $groups]);
    }

    #[Route('/{id}', name: 'admin_provider_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function update(Request $request, Provider $provider): Response
    {
        $this->denyUnlessCsrfValid('provider-'.$provider->getId(), $request);
        $payload = $request->getPayload();
        $old = ['enabled' => $provider->isEnabled(), 'default' => $provider->isDefault(), 'priority' => $provider->getPriority()];

        $provider->setEnabled($payload->getBoolean('enabled'));
        $provider->setPriority(max(-100, min(100, $payload->getInt('priority'))));
        if ($payload->getBoolean('default') && $provider->isEnabled()) {
            foreach ($this->providers->findBy(['type' => $provider->getType(), 'isDefault' => true]) as $other) {
                $other->setDefault(false);
            }
            $provider->setDefault(true);
        } elseif (!$payload->getBoolean('default')) {
            $provider->setDefault(false);
        }
        $this->audit->log('provider.updated', $provider, $old, ['enabled' => $provider->isEnabled(), 'default' => $provider->isDefault(), 'priority' => $provider->getPriority()], ['code' => $provider->getCode()]);
        $this->em->flush();

        $this->addFlash('success', $provider->getName().' saved.');
        if ([] === $this->providers->findBy(['type' => $provider->getType(), 'enabled' => true])) {
            $this->addFlash('error', \sprintf('No %s provider is enabled any more: the related operations will wait for an administrator.', $provider->getType()->value));
        }

        return $this->redirectToRoute('admin_providers', ['_fragment' => 'provider-'.$provider->getId()]);
    }

    #[Route('/{id}/settings', name: 'admin_provider_settings', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function settings(Request $request, Provider $provider, ProviderSettingsValidator $validator): Response
    {
        $this->denyUnlessCsrfValid('provider-'.$provider->getId(), $request);
        try {
            $settings = json_decode($request->getPayload()->getString('settings', '{}'), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->addFlash('error', 'Invalid JSON: '.$e->getMessage());

            return $this->redirectToRoute('admin_providers', ['_fragment' => 'provider-'.$provider->getId()]);
        }
        $problems = $validator->validate($settings);
        if ([] !== $problems) {
            // The submitted value is NOT echoed back: it may contain the secret.
            $this->addFlash('error', 'Settings not saved. '.implode(' ', $problems));

            return $this->redirectToRoute('admin_providers', ['_fragment' => 'provider-'.$provider->getId()]);
        }
        /** @var array<string, mixed> $settings */
        $old = $provider->getSettings();
        $provider->setSettings($settings);
        $this->audit->log('provider.settings_updated', $provider, $old, $settings, ['code' => $provider->getCode()]);
        $this->em->flush();
        $this->addFlash('success', $provider->getName().': settings saved.');

        return $this->redirectToRoute('admin_providers', ['_fragment' => 'provider-'.$provider->getId()]);
    }

    #[Route('/{id}/credentials', name: 'admin_provider_credential', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function credential(Request $request, Provider $provider, CredentialVault $vault): Response
    {
        $this->denyUnlessCsrfValid('provider-'.$provider->getId(), $request);
        $payload = $request->getPayload();
        $name = strtolower(trim($payload->getString('name')));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,39}$/', $name)) {
            $this->addFlash('error', 'Invalid credential name (lowercase letters, digits and underscores).');
        } elseif ($payload->getBoolean('remove')) {
            $vault->remove($provider, $name);
            $this->addFlash('success', \sprintf('%s: credential "%s" removed.', $provider->getName(), $name));
        } else {
            $value = $payload->getString('value');
            if ('' === trim($value) || \strlen($value) > 8192) {
                $this->addFlash('error', 'Enter the secret value (it is encrypted immediately and never displayed again).');
            } else {
                $vault->store($provider, $name, trim($value));
                $this->addFlash('success', \sprintf('%s: credential "%s" encrypted and stored.', $provider->getName(), $name));
            }
        }

        return $this->redirectToRoute('admin_providers', ['_fragment' => 'provider-'.$provider->getId()]);
    }

    /**
     * Where each expected secret comes from — never its value.
     *
     * @return list<array{name: string, source: string, rotated_at: ?\DateTimeImmutable, last_used_at: ?\DateTimeImmutable, env: ?string}>
     */
    private function credentialStatus(Provider $provider): array
    {
        $env = (array) ($provider->getSettings()['env'] ?? []);
        $names = array_unique([...array_map('strval', array_keys($env)), ...$provider->getCredentials()->map(static fn ($c) => $c->getName())->getValues()]);
        sort($names);
        $status = [];
        foreach ($names as $name) {
            $credential = $provider->getCredential($name);
            $variable = isset($env[$name]) && \is_string($env[$name]) ? $env[$name] : null;
            $fromEnv = null !== $variable && '' !== (string) ($_SERVER[$variable] ?? $_ENV[$variable] ?? getenv($variable) ?: '');
            $status[] = [
                'name' => $name,
                'source' => null !== $credential ? 'encrypted' : ($fromEnv ? 'environment' : 'missing'),
                'rotated_at' => $credential?->getRotatedAt(),
                'last_used_at' => $credential?->getLastUsedAt(),
                'env' => $variable,
            ];
        }

        return $status;
    }
}
