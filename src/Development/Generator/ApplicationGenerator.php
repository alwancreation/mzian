<?php

declare(strict_types=1);

namespace App\Development\Generator;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Generates a complete customer application from a template:
 *
 *   public/        website (1 to 3 languages, SEO, sitemap, forms) + admin/ front controller
 *   app/           management application (MzianApp engine + schema.json; config.php at deployment)
 *   mzian.json     manifest (pages, entities, feature coverage) used by the Testing and QA agents
 *   README.md, DEPLOY.md, .gitignore
 *
 * Deterministic for the same input: regenerating never changes an unchanged project.
 * Features that cannot be generated are listed (never faked) in the manifest and README.
 */
final class ApplicationGenerator
{
    private const CORE_PAGES = ['index' => 'home', 'about' => 'about', 'contact' => 'contact', 'legal' => 'legal'];
    private const FEATURED = ['vehicles', 'menu', 'rooms', 'services', 'shop', 'properties', 'events', 'blog'];
    private const CTA_PAGES = ['booking', 'appointment', 'shop', 'quote'];
    private const YOUR_CITY = ['fr' => 'votre ville', 'en' => 'your city', 'ar' => 'مدينتك'];

    private ?Environment $twig = null;

    public function __construct(
        private readonly TemplateCatalog $catalog,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function generate(GenerationRequest $request, string $output): GenerationResult
    {
        $template = $this->catalog->template($request->template);
        $features = array_values(array_unique([...(array) ($template['base_features'] ?? []), ...$request->features]));
        $coverage = $this->catalog->coverage($features);
        $modules = $this->catalog->modules();
        $languages = \in_array('multilingual', $coverage['implemented'], true)
            ? array_values(array_unique([$request->locale, ...TemplateCatalog::LOCALES]))
            : [$request->locale];

        $this->clean($output);
        $files = [];
        $pages = [];
        $publicPages = $coverage['pages'];
        if (\in_array('orders', $coverage['entities'], true) && \in_array('shop', $publicPages, true) && !\in_array('checkout', $publicPages, true)) {
            $publicPages[] = 'checkout';
        }

        foreach ($languages as $index => $locale) {
            $prefix = 0 === $index ? '' : $locale.'/';
            $site = $this->site($request, $template, $coverage, $publicPages, $languages, $locale, $prefix);
            $definitions = self::CORE_PAGES + array_combine($publicPages, array_map(static fn (string $p) => (string) $modules['pages'][$p]['type'], $publicPages));
            $definitions['404'] = '404';
            foreach ($definitions as $file => $type) {
                $page = $this->page($request, $site, (string) $file, $type, $locale);
                $path = 'public/'.$prefix.$file.'.html';
                $this->write($output, $path, $this->twig()->render('pages/'.$type.'.html.twig', ['site' => $site, 'page' => $page]), $files);
                if ('404' !== (string) $file) {
                    $pages[] = $prefix.$file.'.html';
                }
            }
            if (0 === $index) {
                $this->write($output, 'public/assets/style.css', $this->twig()->render('assets/style.css.twig', ['site' => $site]), $files);
                $this->write($output, 'public/assets/favicon.svg', $this->twig()->render('assets/favicon.svg.twig', ['site' => $site]), $files);
                if (\in_array('gallery', $publicPages, true)) {
                    for ($i = 1; $i <= 6; ++$i) {
                        $this->write($output, 'public/assets/gallery-'.$i.'.svg', $this->twig()->render('assets/gallery.svg.twig', ['site' => $site, 'index' => $i]), $files);
                    }
                }
            }
        }
        $this->write($output, 'public/assets/app.js', (string) file_get_contents($this->catalog->directory().'/_site/assets/app.js'), $files);
        $this->write($output, 'public/robots.txt', "User-agent: *\nAllow: /\nDisallow: /admin/\n\nSitemap: ".$this->base($request).'sitemap.xml'."\n", $files);
        $this->write($output, 'public/sitemap.xml', $this->sitemap($request, $pages), $files);
        $this->write($output, 'public/site.webmanifest', (string) json_encode([
            'name' => $request->businessName,
            'short_name' => mb_substr($request->businessName, 0, 12),
            'start_url' => './index.html',
            'display' => 'standalone',
            'theme_color' => $template['theme']['primary'],
            'background_color' => '#ffffff',
            'icons' => [['src' => 'assets/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n", $files);

        // Management application.
        foreach (['app/MzianApp.php', 'app/bootstrap.php', 'app/config.example.php', 'app/.htaccess', 'public/admin/index.php'] as $file) {
            $this->write($output, $file, (string) file_get_contents($this->catalog->directory().'/_engine/'.$file), $files);
        }
        $schema = $this->schema($request, $template, $coverage, $features);
        $this->write($output, 'app/schema.json', json_encode($schema, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n", $files);

        $manifest = [
            'generator' => 'Mzian.net',
            'template' => $request->template,
            'template_version' => (string) ($template['version'] ?? '1.0.0'),
            'version' => $request->version,
            'name' => $request->businessName,
            'languages' => $languages,
            'pages' => $pages,
            'entities' => array_keys($schema['entities']),
            'public_forms' => array_keys(array_filter($schema['entities'], static fn ($e) => true === $e['public_form'])),
            'features' => [
                'implemented' => $coverage['implemented'],
                'configuration' => $coverage['configuration'],
                'custom' => $coverage['custom'],
            ],
            'admin' => 'admin/',
        ];
        $this->write($output, 'mzian.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n", $files);
        $this->write($output, '.gitignore', "/app/config.php\n/app/data/\n/data/\n.DS_Store\n", $files);
        $this->write($output, 'README.md', $this->readme($request, $template, $manifest), $files);
        $this->write($output, 'DEPLOY.md', $this->deployGuide(), $files);

        sort($files);

        return new GenerationResult($output, $files, $languages, $pages, $manifest['entities'], $manifest['features'], $manifest);
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $coverage
     * @param list<string>         $publicPages
     * @param list<string>         $languages
     *
     * @return array<string, mixed>
     */
    private function site(GenerationRequest $request, array $template, array $coverage, array $publicPages, array $languages, string $locale, string $prefix): array
    {
        $i18n = $this->catalog->i18n($locale);
        $modules = $this->catalog->modules();
        $city = null !== $request->city && '' !== trim($request->city) ? trim($request->city) : null;
        $replace = ['%name%' => $request->businessName, '%city%' => $city ?? self::YOUR_CITY[$locale] ?? ''];
        $content = $request->content[$locale] ?? $request->content[$request->locale] ?? [];
        $content += ['tagline' => $request->businessName, 'about' => '', 'services' => [], 'call_to_action' => $i18n['site']['contact'], 'seo_title' => $request->businessName, 'seo_description' => $request->businessName];
        $implemented = $coverage['implemented'];

        $nav = [['file' => 'index.html', 'label' => $i18n['site']['home']]];
        foreach ($publicPages as $page) {
            if (true === ($modules['pages'][$page]['nav'] ?? false)) {
                $nav[] = ['file' => $page.'.html', 'label' => $i18n['pages'][$page][0]];
            }
        }
        $nav[] = ['file' => 'about.html', 'label' => $i18n['site']['about']];
        $nav[] = ['file' => 'contact.html', 'label' => $i18n['site']['contact']];

        $ctaPage = null;
        foreach (self::CTA_PAGES as $candidate) {
            if (\in_array($candidate, $publicPages, true)) {
                $ctaPage = $candidate;
                break;
            }
        }
        $featured = null;
        foreach (self::FEATURED as $candidate) {
            if (\in_array($candidate, $publicPages, true)) {
                $featured = $this->collection($request, $modules['pages'][$candidate]['entity'], $locale, 'shop' === $candidate) + ['file' => $candidate.'.html', 'title' => $i18n['pages'][$candidate][0]];
                break;
            }
        }

        $phone = null !== $request->phone && '' !== trim($request->phone) ? trim($request->phone) : null;
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);
        // International number (+212…/00212…) → usable for WhatsApp links.
        $international = match (true) {
            null === $phone => null,
            str_starts_with($phone, '+') => $digits,
            str_starts_with($phone, '00') => substr($digits, 2),
            default => null,
        };

        return [
            'name' => $request->businessName,
            'slug' => $request->slug,
            'initial' => mb_strtoupper(mb_substr($request->businessName, 0, 1)),
            'city' => $city,
            'locale' => $locale,
            'dir' => 'ar' === $locale ? 'rtl' : 'ltr',
            'root' => '' === $prefix ? '' : '../',
            'prefix' => $prefix,
            'year' => date('Y'),
            'currency' => $request->currency,
            'languages' => array_map(fn (string $l, int $i) => ['code' => $l, 'prefix' => 0 === $i ? '' : $l.'/', 'base' => $this->base($request).(0 === $i ? '' : $l.'/')], $languages, array_keys($languages)),
            'theme' => $template['theme'],
            't' => $i18n['site'],
            'hero' => ['title' => strtr((string) $template['hero'][$locale]['title'], $replace), 'subtitle' => strtr((string) $template['hero'][$locale]['subtitle'], $replace)],
            'highlights' => array_map(static fn ($line) => strtr((string) $line, $replace), (array) ($template['highlights'][$locale] ?? [])),
            'hours' => \in_array('opening_hours', $implemented, true) ? (array) ($template['hours'][$locale] ?? []) : null,
            'content' => $content,
            'phone' => $phone,
            'phone_link' => null !== $international ? '+'.$international : $digits,
            'email' => $request->email,
            'whatsapp' => \in_array('whatsapp_button', $implemented, true) ? $international : null,
            'map' => \in_array('google_maps', $implemented, true),
            'newsletter' => \in_array('subscribers', $coverage['entities'], true),
            'reviews' => \in_array('reviews', $coverage['entities'], true),
            'has_cart' => \in_array('checkout', $publicPages, true),
            'nav' => $nav,
            'cta' => null !== $ctaPage ? ['file' => $ctaPage.'.html', 'label' => $i18n['pages'][$ctaPage][0]] : ['file' => 'contact.html', 'label' => $i18n['site']['contact']],
            'featured' => $featured,
            'jsonld' => (string) json_encode(array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'LocalBusiness',
                'name' => $request->businessName,
                'description' => $content['seo_description'],
                'url' => $this->base($request).$prefix,
                'telephone' => $phone,
                'email' => $request->email,
                'address' => null !== $city ? ['@type' => 'PostalAddress', 'addressLocality' => $city] : null,
            ]), \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param array<string, mixed> $site
     *
     * @return array<string, mixed>
     */
    private function page(GenerationRequest $request, array $site, string $file, string $type, string $locale): array
    {
        $i18n = $this->catalog->i18n($locale);
        $modules = $this->catalog->modules();
        $definition = $modules['pages'][$file] ?? [];
        [$title, $intro] = $i18n['pages'][$file] ?? ['index' === $file ? $site['name'] : $file, ''];
        $entity = match ($file) {
            'contact' => 'messages',
            default => $definition['entity'] ?? null,
        };

        $page = [
            'file' => $file.'.html',
            'type' => $type,
            'title' => $title,
            'intro' => $intro,
            'url' => $this->base($request).$site['prefix'].('index' === $file ? '' : $file.'.html'),
            'seo_title' => 'index' === $file ? mb_substr((string) $site['content']['seo_title'], 0, 70) : $title.' — '.$site['name'],
            'seo_description' => 'index' === $file || '' === $intro ? mb_substr((string) $site['content']['seo_description'], 0, 170) : $intro.' '.$site['name'].($site['city'] ? ', '.$site['city'] : '').'.',
            'entity' => $entity,
            'form_fields' => [],
            'collection' => null,
            'datalist' => [],
        ];
        if (null !== $entity && isset($modules['entities'][$entity]['public_form'])) {
            $page['form_fields'] = $this->publicFormFields($entity, $locale);
        }
        if (\in_array($type, ['collection', 'shop'], true) && null !== $entity) {
            $page['collection'] = $this->collection($request, $entity, $locale, 'shop' === $type);
        }
        if ('form' === $type && null !== $site['featured']) {
            $page['datalist'] = array_values(array_filter(array_map(static fn ($row) => (string) ($row['name'] ?? ''), $site['featured']['items'])));
        }

        return $page;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicFormFields(string $entity, string $locale): array
    {
        $definition = $this->catalog->modules()['entities'][$entity];
        $fields = [];
        foreach ($this->fields($entity, $locale) as $field) {
            if (\in_array($field['name'], (array) $definition['public_form'], true)) {
                $field['readonly'] = 'orders' === $entity && 'items' === $field['name'];
                $fields[] = $field;
            }
        }
        usort($fields, static fn ($a, $b) => array_search($a['name'], $definition['public_form'], true) <=> array_search($b['name'], $definition['public_form'], true));

        return $fields;
    }

    /**
     * @return array{entity: string, fields: list<array<string, mixed>>, items: list<array<string, mixed>>, shop: bool}
     */
    private function collection(GenerationRequest $request, string $entity, string $locale, bool $shop): array
    {
        $definition = $this->catalog->modules()['entities'][$entity];
        $publicFields = (array) ($definition['public_list']['fields'] ?? []);
        $fields = array_values(array_filter($this->fields($entity, $locale), static fn ($f) => \in_array($f['name'], $publicFields, true)));
        $items = [];
        foreach ($this->seed($request->template, $entity, $locale) as $index => $row) {
            $items[] = ['id' => $index + 1] + $row;
        }

        return ['entity' => $entity, 'fields' => $fields, 'items' => $items, 'shop' => $shop];
    }

    /**
     * @return list<array{name: string, type: string, label: string, required: bool, options: array<string, string>}>
     */
    private function fields(string $entity, string $locale): array
    {
        $i18n = $this->catalog->i18n($locale);
        $fields = [];
        foreach ($this->catalog->modules()['entities'][$entity]['fields'] as $spec) {
            $field = TemplateCatalog::parseField((string) $spec);
            $options = [];
            foreach ($field['options'] as $value) {
                $options[$value] = (string) ($i18n['options'][$value] ?? $value);
            }
            $fields[] = [
                'name' => $field['name'],
                'type' => $field['type'],
                'label' => (string) ($i18n['labels'][$field['name']] ?? $field['name']),
                'required' => $field['required'],
                'options' => $options,
            ];
        }

        return $fields;
    }

    /**
     * Example rows of the template, in the requested language.
     *
     * @return list<array<string, string>>
     */
    private function seed(string $template, string $entity, string $locale): array
    {
        $rows = [];
        foreach ((array) ($this->catalog->template($template)['seed'][$entity] ?? []) as $row) {
            $rows[] = array_map(static fn ($value) => \is_array($value) ? (string) ($value[$locale] ?? reset($value)) : (string) $value, (array) $row);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $coverage
     * @param list<string>         $features
     *
     * @return array<string, mixed>
     */
    private function schema(GenerationRequest $request, array $template, array $coverage, array $features): array
    {
        $locale = $request->locale;
        $i18n = $this->catalog->i18n($locale);
        $modules = $this->catalog->modules();
        $stats = \in_array('statistics', $coverage['implemented'], true) || \in_array('dashboard', $coverage['implemented'], true);
        $entities = [];
        foreach ($coverage['entities'] as $code) {
            $definition = $modules['entities'][$code];
            $entities[$code] = [
                'label' => $i18n['entities'][$code][0],
                'singular' => $i18n['entities'][$code][1],
                'icon' => (string) ($definition['icon'] ?? '📁'),
                'title' => (string) $definition['title'],
                'fields' => $this->fields($code, $locale),
                'list' => (array) ($definition['list'] ?? [$definition['title']]),
                'public_form' => isset($definition['public_form']),
                'form_fields' => (array) ($definition['public_form'] ?? []),
                'form_defaults' => (array) ($definition['form_defaults'] ?? []),
                'public_list' => isset($definition['public_list']),
                'public_fields' => (array) ($definition['public_list']['fields'] ?? []),
                'public_filter' => (array) ($definition['public_list']['filter'] ?? []),
                'printable' => (bool) ($definition['printable'] ?? false),
                'calendar' => $definition['calendar'] ?? null,
                'stats' => $stats && (bool) ($definition['stats'] ?? false),
                'confirm_email' => \in_array('email_confirmations', $features, true) && isset($definition['public_form']),
                'seed' => $this->seed($request->template, $code, $locale),
            ];
        }

        return [
            'app' => [
                'name' => $request->businessName,
                'locale' => $locale,
                'dir' => 'ar' === $locale ? 'rtl' : 'ltr',
                'currency' => $request->currency,
                'primary' => $template['theme']['primary'],
                'site_url' => '../',
            ],
            'ui' => $i18n['ui'],
            'entities' => $entities,
        ];
    }

    /**
     * @param list<string> $pages
     */
    private function sitemap(GenerationRequest $request, array $pages): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($pages as $page) {
            if (str_ends_with($page, 'checkout.html')) {
                continue;
            }
            $path = preg_replace('#(^|/)index\.html$#', '$1', $page);
            $xml .= '  <url><loc>'.htmlspecialchars($this->base($request).$path, \ENT_XML1).'</loc><lastmod>'.date('Y-m-d').'</lastmod></url>'."\n";
        }

        return $xml.'</urlset>'."\n";
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $manifest
     */
    private function readme(GenerationRequest $request, array $template, array $manifest): string
    {
        $lines = [
            '# '.$request->businessName,
            '',
            'Application generated by [Mzian.net](https://mzian.net) from the "'.$template['name'].'" template (v'.$manifest['template_version'].'), version '.$request->version.'.',
            '',
            '## Contents',
            '',
            '- `public/` — website ('.implode(', ', $manifest['languages']).'): '.\count($manifest['pages']).' pages, sitemap, robots.txt.',
            '- `public/admin/` — management application: '.implode(', ', $manifest['entities']).'.',
            '- `app/` — engine and schema of the management application (outside the web root). Data: SQLite in `app/data/` (or the `data_dir` of `app/config.php`).',
            '',
            '## Features',
            '',
        ];
        foreach ($manifest['features']['implemented'] as $feature) {
            $lines[] = '- ✅ '.$feature;
        }
        foreach ($manifest['features']['configuration'] as $feature => $note) {
            $lines[] = '- ⚙️ '.$feature.' — '.$note;
        }
        foreach ($manifest['features']['custom'] as $feature) {
            $lines[] = '- 🛠️ '.$feature.' — not generated automatically: developed by the Mzian team.';
        }
        array_push($lines, '', '## Run locally', '', '```bash', 'cp app/config.example.php app/config.php   # then set the admin e-mail and password hash', 'php -S localhost:8000 -t public', '```', '', 'Website: http://localhost:8000 — administration: http://localhost:8000/admin/', '', 'See DEPLOY.md to put it online.', '');

        return implode("\n", $lines);
    }

    private function deployGuide(): string
    {
        return <<<'MD'
            # Deployment

            Requirements: PHP 8.1+ with `pdo_sqlite` and `mbstring` (any shared hosting).

            1. Upload the repository; the **web root** must be `public/` (the `app/` directory must not be reachable over HTTP; an `.htaccess` denies it on Apache).
            2. Create `app/config.php` from `app/config.example.php` (never commit it): admin e-mail, password hash (`php -r 'echo password_hash("…", PASSWORD_DEFAULT);'`), notification e-mail, `data_dir`.
            3. Make `data_dir` writable by PHP. The database is created on the first request.
            4. Enable HTTPS (Let's Encrypt) — the admin session cookie is marked `secure` on HTTPS.
            5. Back up `data_dir` daily.

            Mzian.net deploys and updates this application automatically for its customers.

            MD;
    }

    private function base(GenerationRequest $request): string
    {
        return rtrim($request->baseUrl, '/').'/';
    }

    private function clean(string $output): void
    {
        $this->filesystem->mkdir($output);
        foreach ((new Finder())->in($output)->depth(0)->ignoreDotFiles(false)->notName('.git') as $entry) {
            $this->filesystem->remove($entry->getPathname());
        }
    }

    /**
     * @param list<string> $files
     */
    private function write(string $output, string $path, string $content, array &$files): void
    {
        $this->filesystem->dumpFile($output.'/'.$path, $content);
        $files[] = $path;
    }

    private function twig(): Environment
    {
        return $this->twig ??= new Environment(new FilesystemLoader($this->catalog->directory().'/_site'), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => false,
        ]);
    }
}
