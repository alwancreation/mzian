<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Analytics\AnalyticsService;
use App\Project\Enum\ProjectStatus;
use App\Project\Repository\ProjectRepository;
use App\Shared\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', defaults: ['_locale' => 'en'])]
final class DashboardController extends AbstractController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, AnalyticsService $analytics, ProjectRepository $projects, AuditLogRepository $logs): Response
    {
        $days = \in_array($request->query->getInt('days', 30), [7, 30, 90, 365], true) ? $request->query->getInt('days', 30) : 30;
        $since = new \DateTimeImmutable("-{$days} days");

        return $this->render('admin/dashboard.html.twig', [
            'days' => $days,
            'metrics' => $analytics->metrics($since),
            'funnel' => $analytics->funnel($since),
            'needs_attention' => $projects->findByStatuses([ProjectStatus::PendingAdminApproval, ProjectStatus::WaitingAdminApproval, ProjectStatus::Failed], 10),
            'in_progress' => $projects->findByStatuses(ProjectStatus::automationStates(), 10),
            'recent_logs' => $logs->findBy([], ['id' => 'DESC'], 8),
        ]);
    }
}
