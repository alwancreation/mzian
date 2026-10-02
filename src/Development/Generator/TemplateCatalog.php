<?php

declare(strict_types=1);

namespace App\Development\Generator;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Application templates and modules (resources/application-templates): data,
 * not code. A template = theme + base features + copy + example data; modules
 * describe how each catalog feature is implemented (entities, pages).
 */
final class TemplateCatalog
{
    public const LOCALES = ['fr', 'en', 'ar'];

    /** @var array<string, mixed>|null */
    private ?array $modules = null;
    /** @var array<string, mixed>|null */
    private ?array $i18n = null;
    /** @var array<string, array<string, mixed>> */
    private array $templates = [];

    public function __construct(
        #[Autowire('%mzian.application_templates_dir%')]
        private readonly string $directory,
    ) {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = [];
        foreach (glob($this->directory.'/*/template.yaml') ?: [] as $file) {
            $codes[] = basename(\dirname($file));
        }
        sort($codes);

        return $codes;
    }

    public function has(string $code): bool
    {
        return 1 === preg_match('/^[a-z0-9-]+$/', $code) && is_file($this->directory.'/'.$code.'/template.yaml');
    }

    /**
     * @return array<string, mixed>
     */
    public function template(string $code): array
    {
        if (!$this->has($code)) {
            throw new \InvalidArgumentException(\sprintf('Unknown application template "%s".', $code));
        }

        return $this->templates[$code] ??= ['code' => $code] + Yaml::parseFile($this->directory.'/'.$code.'/template.yaml');
    }

    /**
     * @return array{entities: array<string, array<string, mixed>>, pages: array<string, array<string, mixed>>, features: array<string, array<string, mixed>>}
     */
    public function modules(): array
    {
        return $this->modules ??= Yaml::parseFile($this->directory.'/modules.yaml');
    }

    /**
     * @return array<string, mixed>
     */
    public function i18n(string $locale): array
    {
        $this->i18n ??= Yaml::parseFile($this->directory.'/i18n.yaml');

        return $this->i18n[$locale] ?? $this->i18n['fr'];
    }

    /**
     * "price:money!" / "status:select(a,b)" → field definition.
     *
     * @return array{name: string, type: string, required: bool, options: list<string>}
     */
    public static function parseField(string $spec): array
    {
        if (1 !== preg_match('/^([a-z_]+):([a-z]+)(?:\(([^)]*)\))?(!)?$/', $spec, $m)) {
            throw new \InvalidArgumentException('Invalid field definition: '.$spec);
        }

        return [
            'name' => $m[1],
            'type' => $m[2],
            'required' => isset($m[4]), // trailing "!"
            'options' => isset($m[3]) && '' !== $m[3] ? array_map('trim', explode(',', $m[3])) : [],
        ];
    }

    /**
     * How a set of features is covered by the generator.
     *
     * @param list<string> $features
     *
     * @return array{implemented: list<string>, configuration: array<string, string>, custom: list<string>, entities: list<string>, pages: list<string>}
     */
    public function coverage(array $features): array
    {
        $modules = $this->modules();
        $result = ['implemented' => [], 'configuration' => [], 'custom' => [], 'entities' => ['messages'], 'pages' => []];
        foreach (array_values(array_unique($features)) as $feature) {
            $module = $modules['features'][$feature] ?? ['status' => 'custom'];
            match ($module['status']) {
                'implemented' => $result['implemented'][] = $feature,
                'configuration' => $result['configuration'][$feature] = (string) ($module['note'] ?? ''),
                default => $result['custom'][] = $feature,
            };
            if ('implemented' === $module['status']) {
                array_push($result['entities'], ...($module['entities'] ?? []));
                array_push($result['pages'], ...($module['pages'] ?? []));
            }
        }
        $result['entities'] = array_values(array_unique($result['entities']));
        $result['pages'] = array_values(array_unique($result['pages']));

        return $result;
    }
}
