<?php

declare(strict_types=1);

namespace App\Shared\Routing;

/**
 * Locale prefixes shared by every public (customer facing) controller.
 *
 * Usage: #[Route(LocalizedRoute::PREFIX)] on the controller class, combined with
 * localized paths on actions, e.g. #[Route(['fr' => '/tarifs', 'en' => '/pricing', 'ar' => '/pricing'], name: 'pricing')].
 */
final class LocalizedRoute
{
    public const PREFIX = ['fr' => '/fr', 'en' => '/en', 'ar' => '/ar'];

    public const LOCALES = ['fr', 'en', 'ar'];

    public const DEFAULT_LOCALE = 'fr';

    public const RTL_LOCALES = ['ar'];

    private function __construct()
    {
    }
}
