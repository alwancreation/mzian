<?php

declare(strict_types=1);

namespace App\Api\Controller\V1;

use App\Catalog\Entity\Solution;
use App\Catalog\Entity\SolutionFeature;
use App\Catalog\Service\CatalogProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/solutions')]
final class SolutionController extends AbstractController
{
    #[Route('', name: 'api_solutions', methods: ['GET'])]
    public function list(Request $request, CatalogProvider $catalog): JsonResponse
    {
        $locale = \in_array($request->query->getString('locale'), ['fr', 'en', 'ar'], true) ? $request->query->getString('locale') : 'fr';
        $sector = $request->query->getString('sector');
        $solutions = '' !== $sector ? $catalog->solutionsForSector($sector) : $catalog->enabledSolutions();

        $response = $this->json(['data' => array_map(fn (Solution $s) => $this->serialize($s, $locale), $solutions)]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Solution $solution, string $locale): array
    {
        return [
            'code' => $solution->getCode(),
            'slug' => $solution->getSlug($locale),
            'name' => $solution->getName($locale),
            'description' => $solution->getShortDescription($locale),
            'category' => $solution->getCategory()->value,
            'estimated_development_days' => $solution->getEstimatedDevelopmentDays(),
            'maintenance_price' => $solution->getMaintenancePrice(),
            'sectors' => $solution->getSectors(),
            'domain_required' => $solution->isDomainRequired(),
            'features' => array_map(static fn (SolutionFeature $f) => [
                'code' => $f->getCode(),
                'name' => $f->getName($locale),
                'included' => $f->isIncluded(),
                'option_price' => $f->isIncluded() ? null : $f->getPrice(),
            ], $solution->getEnabledFeatures()),
            'url' => $this->generateUrl('solution_show', ['_locale' => $locale, 'slug' => $solution->getSlug($locale)], 0),
        ];
    }
}
