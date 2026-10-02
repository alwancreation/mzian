<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Catalog\Service\CatalogProvider;
use App\Shared\Routing\LocalizedRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class SeoController extends AbstractController
{
    private const STATIC_ROUTES = ['home', 'solutions_index', 'industries_index', 'pricing', 'how_it_works', 'faq', 'contact', 'start', 'chat', 'legal_terms', 'legal_privacy'];

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'], format: 'xml')]
    public function sitemap(CatalogProvider $catalog, RouterInterface $router): Response
    {
        $routes = $router->getRouteCollection();
        $entries = [];
        foreach (self::STATIC_ROUTES as $route) {
            if (null !== $routes->get($route.'.fr')) {
                $entries[] = $this->entry($route, []);
            }
        }
        foreach ($catalog->enabledSolutions() as $solution) {
            $entries[] = $this->entry('solution_show', array_combine(LocalizedRoute::LOCALES, array_map(static fn (string $l) => ['slug' => $solution->getSlug($l)], LocalizedRoute::LOCALES)), $solution->getUpdatedAt());
        }
        foreach ($catalog->enabledSectors() as $sector) {
            $entries[] = $this->entry('industry_show', array_combine(LocalizedRoute::LOCALES, array_map(static fn (string $l) => ['slug' => $sector->getSlug($l)], LocalizedRoute::LOCALES)));
        }

        $response = $this->render('seo/sitemap.xml.twig', ['entries' => $entries]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    #[Route('/robots.txt', name: 'robots', methods: ['GET'], format: 'txt')]
    public function robots(): Response
    {
        $content = implode("\n", [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /api/',
            'Disallow: /webhooks/',
            'Disallow: /preview/',
            'Disallow: /*/compte',
            'Disallow: /*/account',
            'Disallow: /*/start/',
            'Disallow: /*/demarrer/',
            'Disallow: /*/proposition/',
            'Disallow: /*/proposal/',
            '',
            'Sitemap: '.$this->generateUrl('sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL),
            '',
        ]);

        return new Response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * @param array<string, array<string, string>> $paramsByLocale
     *
     * @return array{urls: array<string, string>, lastmod: ?string}
     */
    private function entry(string $route, array $paramsByLocale, ?\DateTimeImmutable $lastModified = null): array
    {
        $urls = [];
        foreach (LocalizedRoute::LOCALES as $locale) {
            $urls[$locale] = $this->generateUrl($route, ['_locale' => $locale] + ($paramsByLocale[$locale] ?? []), UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return ['urls' => $urls, 'lastmod' => $lastModified?->format('Y-m-d')];
    }
}
