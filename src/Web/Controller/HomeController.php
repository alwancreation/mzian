<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Billing\Repository\SubscriptionPlanRepository;
use App\Catalog\Service\CatalogProvider;
use App\Pricing\ProposalBuilder;
use App\Shared\Routing\LocalizedRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * Sends visitors to their preferred language (Accept-Language), French by default.
     */
    #[Route('/', name: 'root', methods: ['GET'])]
    public function root(Request $request): RedirectResponse
    {
        $locale = $request->getPreferredLanguage(LocalizedRoute::LOCALES) ?? LocalizedRoute::DEFAULT_LOCALE;

        return $this->redirectToRoute('home', ['_locale' => $locale]);
    }

    #[Route(['fr' => '/fr/', 'en' => '/en/', 'ar' => '/ar/'], name: 'home', methods: ['GET'])]
    public function index(CatalogProvider $catalog, SubscriptionPlanRepository $plans, ProposalBuilder $proposals): Response
    {
        $solutions = $catalog->enabledSolutions();
        $featured = array_values(array_filter($solutions, static fn ($s) => $s->isFeatured()));
        $shown = \array_slice([] !== $featured ? $featured : $solutions, 0, 6);

        $response = $this->render('web/home.html.twig', [
            'sectors' => $catalog->enabledSectors(),
            'solutions' => $shown,
            'starting_prices' => $proposals->startingPrices($shown),
            'plans' => $plans->findBy(['enabled' => true], ['position' => 'ASC']),
            'faq_keys' => ['what', 'how_long', 'price', 'ai'],
        ]);
        if (null === $this->getUser()) {
            $response->setPublic();
            $response->setMaxAge(300);
        }

        return $response;
    }
}
