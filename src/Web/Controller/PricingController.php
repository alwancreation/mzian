<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Billing\Repository\SubscriptionPlanRepository;
use App\Catalog\Service\CatalogProvider;
use App\Pricing\ProposalBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public pricing: "starting at" price of every solution (computed by the pricing
 * engine from the catalog, the hosting plans and the registrar prices) and the plans.
 */
final class PricingController extends AbstractController
{
    #[Route(['fr' => '/fr/tarifs', 'en' => '/en/pricing', 'ar' => '/ar/pricing'], name: 'pricing', methods: ['GET'])]
    public function index(Request $request, CatalogProvider $catalog, ProposalBuilder $proposals, SubscriptionPlanRepository $plans): Response
    {
        $solutions = $catalog->enabledSolutions();
        $prices = $proposals->startingPrices($solutions, $request->getLocale());
        usort($solutions, static fn ($a, $b) => ($prices[$a->getCode()] ?? \PHP_INT_MAX) <=> ($prices[$b->getCode()] ?? \PHP_INT_MAX));

        $response = $this->render('web/pricing.html.twig', [
            'solutions' => $solutions,
            'starting_prices' => $prices,
            'plans' => $plans->findBy(['enabled' => true], ['position' => 'ASC']),
            'quote_validity_days' => $proposals->policy()->quoteValidityDays,
        ]);
        if (null === $this->getUser()) {
            $response->setPublic();
            $response->setMaxAge(300);
        }

        return $response;
    }
}
