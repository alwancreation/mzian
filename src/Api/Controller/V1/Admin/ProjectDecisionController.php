<?php

declare(strict_types=1);

namespace App\Api\Controller\V1\Admin;

use App\Api\Dto\AdminDecisionRequest;
use App\Api\Presenter\CommercePresenter;
use App\Project\Approval\ApprovalService;
use App\Project\Entity\Project;
use App\Project\Enum\ProjectStatus;
use App\Project\Repository\ProjectRepository;
use App\Project\Workflow\IllegalTransitionException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administrator decisions through the API (admin API tokens only).
 * Same rules as the admin UI: the state machine refuses anything not allowed.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/api/v1/admin')]
final class ProjectDecisionController extends AbstractController
{
    public function __construct(private readonly ApprovalService $approvals)
    {
    }

    #[Route('/approvals', name: 'api_admin_approvals', methods: ['GET'])]
    public function approvals(ProjectRepository $projects): JsonResponse
    {
        return $this->json([
            'pending' => array_map($this->present(...), $projects->findByStatuses([ProjectStatus::PendingAdminApproval, ProjectStatus::ChangesRequested])),
            'blocked' => array_map($this->present(...), $projects->findByStatuses([ProjectStatus::WaitingAdminApproval, ProjectStatus::Failed])),
        ]);
    }

    #[Route('/projects/{reference}/{decision}', name: 'api_admin_project_decision', requirements: ['reference' => 'MZ-[0-9]{4}-[A-Z0-9]{6}', 'decision' => 'approve|reject|request-changes|cancel|resume|retry|pause|unpause'], methods: ['POST'])]
    public function decide(#[MapEntity(mapping: ['reference' => 'reference'])] Project $project, string $decision, #[MapRequestPayload] ?AdminDecisionRequest $payload = null): JsonResponse
    {
        $payload ??= new AdminDecisionRequest();
        $message = (string) $payload->message;
        $at = null !== $payload->at ? (ProjectStatus::tryFrom($payload->at) ?? throw new UnprocessableEntityHttpException('Unknown status '.$payload->at)) : null;
        $extra = [];
        try {
            match ($decision) {
                'approve' => $this->approvals->approve($project, '' !== $message ? $message : null),
                'request-changes' => $this->approvals->requestChanges($project, $message),
                'reject' => $extra = $this->approvals->reject($project, $message, $payload->refund),
                'cancel' => $extra = $this->approvals->cancel($project, $message, $payload->refund),
                'resume' => $this->approvals->resume($project, $at),
                'retry' => $this->approvals->retry($project, $at),
                'pause' => $this->approvals->pause($project),
                'unpause' => $this->approvals->unpause($project),
                default => throw $this->createNotFoundException(),
            };
        } catch (IllegalTransitionException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->json($this->present($project) + $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Project $project): array
    {
        $quote = $project->getOrder()?->getQuote() ?? $project->getCurrentQuote();

        return CommercePresenter::project($project) + [
            'customer' => null !== $project->getCustomer() ? ['name' => $project->getCustomer()->getDisplayName(), 'email' => $project->getCustomer()->getEmail()] : null,
            'risk' => ['level' => $project->getRisk(), 'notes' => $project->getRiskNotes()],
            'hold_reason' => $project->getHoldReason(),
            'automation_paused' => $project->isAutomationPaused(),
            'budget' => $project->getBudget(),
            'money' => null !== $quote ? [
                'currency' => $quote->getCurrency(),
                'cost_price' => $quote->getCostPrice(),
                'selling_price' => $quote->getSellingPrice(),
                'margin' => $quote->getMargin(),
                'margin_percentage' => $quote->getMarginPercentage(),
            ] : null,
        ];
    }
}
