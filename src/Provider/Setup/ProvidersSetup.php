<?php

declare(strict_types=1);

namespace App\Provider\Setup;

use App\Billing\Enum\BillingInterval;
use App\Hosting\Entity\HostingPlan;
use App\Hosting\Repository\HostingPlanRepository;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\Enum\ProvisioningMethod;
use App\Provider\Repository\ProviderRepository;
use App\Shared\Setup\SetupStepInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds the providers declared in config/mzian/providers.yaml (only missing ones:
 * admin changes are never overwritten) and their hosting plans.
 */
final readonly class ProvidersSetup implements SetupStepInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProviderRepository $providers,
        private HostingPlanRepository $plans,
        #[Autowire('%kernel.project_dir%/config/mzian/providers.yaml')]
        private string $file,
    ) {
    }

    public static function getPriority(): int
    {
        return 200;
    }

    public function run(SymfonyStyle $io, bool $demo): void
    {
        $created = $this->import();
        $io->writeln(\sprintf('  Providers: +%d providers, +%d hosting plans', $created['providers'], $created['plans']));
    }

    /**
     * @return array{providers: int, plans: int}
     */
    public function import(): array
    {
        $config = Yaml::parseFile($this->file);
        $stats = ['providers' => 0, 'plans' => 0];

        foreach ($config['providers'] as $data) {
            $provider = $this->providers->findOneBy(['code' => $data['code']]);
            if (null === $provider) {
                $type = ProviderType::from($data['type']);
                $provider = new Provider($data['code'], $type, $data['driver'], $data['name'], ProvisioningMethod::from($data['provisioning_method']));
                $provider->setSettings($data['settings'] ?? []);
                $provider->setCapabilities($data['capabilities'] ?? []);
                $provider->setPriority((int) ($data['priority'] ?? 0));
                $provider->setEnabled((bool) ($data['enabled'] ?? false) || self::envMatches($data['enabled_if_env'] ?? []));
                $isDefault = self::envMatches($data['default_if_env'] ?? []) || (bool) ($data['default'] ?? false);
                if ($isDefault && $provider->isEnabled()) {
                    foreach ($this->providers->findBy(['type' => $type, 'isDefault' => true]) as $other) {
                        $other->setDefault(false);
                    }
                    $provider->setDefault(true);
                }
                $this->em->persist($provider);
                $this->em->flush();
                ++$stats['providers'];
            }

            foreach ($data['hosting_plans'] ?? [] as $planData) {
                if (null !== $this->plans->findOneBy(['provider' => $provider, 'code' => $planData['code']])) {
                    continue;
                }
                $plan = new HostingPlan($provider, $planData['code'], $planData['name'], (int) round((float) $planData['price'] * 100), $planData['currency'] ?? 'USD', BillingInterval::from($planData['billing_period']));
                $plan->update($planData['name'], (int) round((float) $planData['price'] * 100), $planData['currency'] ?? 'USD', BillingInterval::from($planData['billing_period']), $planData['specs'] ?? [], $planData['capabilities'] ?? [], true);
                $this->em->persist($plan);
                ++$stats['plans'];
            }
        }
        $this->em->flush();

        return $stats;
    }

    /**
     * @param array<string, string> $conditions e.g. {"MZIAN_AI_PROVIDER": "openai"}
     */
    private static function envMatches(array $conditions): bool
    {
        if ([] === $conditions) {
            return false;
        }
        foreach ($conditions as $name => $expected) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
            if ((string) $value !== (string) $expected) {
                return false;
            }
        }

        return true;
    }
}
