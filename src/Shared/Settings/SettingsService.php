<?php

declare(strict_types=1);

namespace App\Shared\Settings;

use App\Shared\Audit\AuditLogger;
use App\Shared\Entity\Setting;
use App\Shared\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Business configuration: defaults from config/packages/mzian.yaml, overridden by
 * administrators (stored in the `setting` table). Nothing business-related is hardcoded.
 */
final class SettingsService
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /**
     * @param array<string, array<string, mixed>> $defaults
     */
    public function __construct(
        #[Autowire('%mzian.settings_defaults%')]
        private readonly array $defaults,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $value = $this->defaults[$key] ?? [];
        $override = $this->settings->findOneBy(['key' => $key]);
        if (null !== $override) {
            $value = array_replace($value, $override->getValue());
        }

        return $this->cache[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(string $key): array
    {
        return $this->defaults[$key] ?? [];
    }

    /**
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value, ?string $updatedBy = null): void
    {
        $setting = $this->settings->findOneBy(['key' => $key]);
        $old = $setting?->getValue();
        if (null === $setting) {
            $setting = new Setting($key, $value);
            $this->em->persist($setting);
        }
        $setting->update($value, $updatedBy);
        $this->audit->log('settings.updated', $setting, $old, $value, ['key' => $key]);
        $this->em->flush();
        unset($this->cache[$key]);
    }

    public function has(string $key): bool
    {
        return null !== $this->settings->findOneBy(['key' => $key]);
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}
