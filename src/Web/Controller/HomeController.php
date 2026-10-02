<?php

declare(strict_types=1);

namespace App\Web\Controller;

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
    public function index(): Response
    {
        return $this->render('web/home.html.twig');
    }
}
