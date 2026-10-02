<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Catalog\Service\CatalogProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SEO landing pages generated from the catalog: /solutions/{slug} and /secteurs/{slug}.
 */
final class CatalogController extends AbstractController
{
    public function __construct(private readonly CatalogProvider $catalog)
    {
    }

    #[Route(['fr' => '/fr/solutions', 'en' => '/en/solutions', 'ar' => '/ar/solutions'], name: 'solutions_index', methods: ['GET'])]
    public function solutions(): Response
    {
        return $this->cached($this->render('catalog/solutions.html.twig', ['solutions' => $this->catalog->enabledSolutions()]));
    }

    #[Route(['fr' => '/fr/solutions/{slug}', 'en' => '/en/solutions/{slug}', 'ar' => '/ar/solutions/{slug}'], name: 'solution_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function solution(string $slug, Request $request): Response
    {
        $locale = $request->getLocale();
        $solution = $this->catalog->solutionBySlug($slug, $locale) ?? throw $this->createNotFoundException();
        if ($solution->getSlug($locale) !== $slug) {
            return $this->redirectToRoute('solution_show', ['slug' => $solution->getSlug($locale)], Response::HTTP_MOVED_PERMANENTLY);
        }

        $related = array_values(array_filter(
            $this->catalog->enabledSolutions(),
            static fn ($s) => $s !== $solution && [] !== array_intersect($s->getSectors(), $solution->getSectors()),
        ));

        return $this->cached($this->render('catalog/solution.html.twig', [
            'solution' => $solution,
            'sectors' => array_filter(array_map(fn (string $code) => $this->catalog->sector($code), $solution->getSectors())),
            'related' => \array_slice($related, 0, 3),
        ]));
    }

    #[Route(['fr' => '/fr/secteurs', 'en' => '/en/industries', 'ar' => '/ar/industries'], name: 'industries_index', methods: ['GET'])]
    public function industries(): Response
    {
        return $this->cached($this->render('catalog/industries.html.twig', ['sectors' => $this->catalog->enabledSectors()]));
    }

    #[Route(['fr' => '/fr/secteurs/{slug}', 'en' => '/en/industries/{slug}', 'ar' => '/ar/industries/{slug}'], name: 'industry_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function industry(string $slug, Request $request): Response
    {
        $locale = $request->getLocale();
        $sector = $this->catalog->sectorBySlug($slug, $locale) ?? throw $this->createNotFoundException();
        if ($sector->getSlug($locale) !== $slug) {
            return $this->redirectToRoute('industry_show', ['slug' => $sector->getSlug($locale)], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->cached($this->render('catalog/industry.html.twig', [
            'sector' => $sector,
            'solutions' => $this->catalog->solutionsForSector($sector->getCode()),
            'default_solution' => $this->catalog->solution($sector->getDefaultSolutionCode()),
        ]));
    }

    private function cached(Response $response): Response
    {
        if (null === $this->getUser()) {
            $response->setPublic();
            $response->setMaxAge(300);
        }

        return $response;
    }
}
