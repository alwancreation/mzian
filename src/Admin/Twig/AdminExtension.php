<?php

declare(strict_types=1);

namespace App\Admin\Twig;

use App\Admin\Menu\AdminMenu;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Attribute\AsTwigFunction;

final class AdminExtension
{
    private PropertyAccessorInterface $accessor;

    public function __construct(
        private readonly AdminMenu $menu,
        private readonly RouterInterface $router,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessorBuilder()->disableExceptionOnInvalidPropertyPath()->getPropertyAccessor();
    }

    /**
     * @return list<array{label: string, items: list<array{label: string, icon: string, route: string, params: array<string, string>, key: string}>}>
     */
    #[AsTwigFunction('admin_menu')]
    public function adminMenu(): array
    {
        return $this->menu->groups();
    }

    #[AsTwigFunction('route_exists')]
    public function routeExists(string $name): bool
    {
        return null !== $this->router->getRouteCollection()->get($name);
    }

    /**
     * Reads a (possibly nested / nullable) property path, e.g. "project.customer.email".
     */
    #[AsTwigFunction('admin_value')]
    public function value(object $row, string $path): mixed
    {
        try {
            return $this->accessor->getValue($row, $path);
        } catch (\Throwable) {
            return null;
        }
    }

    #[AsTwigFunction('badge_class')]
    public function badgeClass(mixed $value): string
    {
        if ($value instanceof \UnitEnum && method_exists($value, 'badge')) {
            return $value->badge();
        }
        $raw = $value instanceof \BackedEnum ? (string) $value->value : (\is_scalar($value) ? (string) $value : '');

        return match (strtolower($raw)) {
            'succeeded', 'passed', 'paid', 'active', 'sent', 'completed', 'converted', 'analyzed', 'registered' => 'badge-green',
            'failed', 'refunded', 'cancelled', 'void', 'expired' => 'badge-red',
            'pending', 'pending_payment', 'running', 'provisioning', 'quoted', 'retrying', 'waiting_admin', 'requires_action', 'submitted', 'issued' => 'badge-amber',
            'mock', 'agent', 'system' => 'badge-violet',
            'admin', 'customer' => 'badge-blue',
            default => 'badge-gray',
        };
    }
}
