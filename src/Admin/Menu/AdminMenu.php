<?php

declare(strict_types=1);

namespace App\Admin\Menu;

use Symfony\Component\Routing\RouterInterface;

/**
 * Admin sidebar definition. Items whose route is not registered are skipped,
 * so modules can be added or removed without touching the layout.
 */
final readonly class AdminMenu
{
    public function __construct(private RouterInterface $router)
    {
    }

    /**
     * @return list<array{label: string, items: list<array{label: string, icon: string, route: string, params: array<string, string>, key: string}>}>
     */
    public function groups(): array
    {
        $groups = [
            'Overview' => [
                ['Dashboard', '📊', 'admin_dashboard', []],
                ['Pipeline', '🛤️', 'admin_pipeline', []],
                ['Approvals', '✅', 'admin_approvals', []],
            ],
            'Sales' => [
                ['Leads', '🧲', 'admin_list', ['section' => 'leads']],
                ['Requirements', '📝', 'admin_list', ['section' => 'requirements']],
                ['AI Recommendations', '🤖', 'admin_ai_recommendations', []],
                ['Customers', '👥', 'admin_list', ['section' => 'customers']],
                ['Orders', '🧾', 'admin_list', ['section' => 'orders']],
                ['Payments', '💳', 'admin_list', ['section' => 'payments']],
                ['Invoices', '📄', 'admin_list', ['section' => 'invoices']],
            ],
            'Delivery' => [
                ['Projects', '🚀', 'admin_projects', []],
                ['Agents', '🦾', 'admin_agents', []],
                ['Hosting', '🗄️', 'admin_list', ['section' => 'hosting']],
                ['Domains', '🌐', 'admin_list', ['section' => 'domains']],
                ['Deployments', '📦', 'admin_list', ['section' => 'deployments']],
                ['Tests', '🧪', 'admin_list', ['section' => 'tests']],
            ],
            'Platform' => [
                ['Notifications', '🔔', 'admin_list', ['section' => 'notifications']],
                ['Logs', '📜', 'admin_list', ['section' => 'logs']],
                ['Solution Catalog', '🧩', 'admin_catalog', []],
                ['Pricing', '💰', 'admin_pricing', []],
                ['Providers', '🔌', 'admin_providers', []],
                ['Settings', '⚙️', 'admin_settings', []],
            ],
        ];

        $routes = $this->router->getRouteCollection();
        $result = [];
        foreach ($groups as $label => $items) {
            $kept = [];
            foreach ($items as [$itemLabel, $icon, $route, $params]) {
                if (null !== $routes->get($route)) {
                    $kept[] = ['label' => $itemLabel, 'icon' => $icon, 'route' => $route, 'params' => $params, 'key' => $route.($params['section'] ?? '')];
                }
            }
            if ([] !== $kept) {
                $result[] = ['label' => $label, 'items' => $kept];
            }
        }

        return $result;
    }
}
