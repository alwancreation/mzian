<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Billing\Entity\Payment;
use App\Billing\Enum\PaymentStatus;
use App\Billing\Repository\PaymentRepository;
use App\Billing\Service\PaymentService;
use App\Catalog\Service\CatalogProvider;
use App\Project\Approval\ApprovalService;
use App\Project\Entity\Project;
use App\Project\Enum\ProjectStatus;
use App\Project\Repository\ProjectRepository;
use App\Project\Workflow\IllegalTransitionException;
use App\Shared\Controller\CsrfGuardTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin > Approvals and Projects. Every decision is a state machine transition
 * taken by the logged-in human administrator (recorded in the timeline and audit log).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', defaults: ['_locale' => 'en'])]
final class ProjectAdminController extends AbstractController
{
    use CsrfGuardTrait;

    private const ACTIONS = ['approve', 'request_changes', 'reject', 'cancel', 'resume', 'retry', 'pause', 'unpause'];

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ApprovalService $approvals,
    ) {
    }

    #[Route('/approvals', name: 'admin_approvals', methods: ['GET'])]
    public function approvals(PaymentRepository $payments): Response
    {
        $transfers = array_values(array_filter(
            $payments->findBy(['status' => PaymentStatus::Pending], ['id' => 'ASC']),
            static fn (Payment $p) => true === ($p->getMetadata()['offline'] ?? false),
        ));

        return $this->render('admin/projects/approvals.html.twig', [
            'pending' => $this->projects->findByStatuses([ProjectStatus::PendingAdminApproval, ProjectStatus::ChangesRequested]),
            'blocked' => $this->projects->findByStatuses([ProjectStatus::WaitingAdminApproval, ProjectStatus::Failed]),
            'transfers' => $transfers,
        ]);
    }

    #[Route('/projects', name: 'admin_projects', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = ProjectStatus::tryFrom($request->query->getString('status'));
        $query = trim($request->query->getString('q'));
        $qb = $this->projects->createQueryBuilder('p')
            ->leftJoin('p.customer', 'c')->addSelect('c')
            ->leftJoin('p.solution', 's')->addSelect('s')
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults(100);
        if (null !== $status) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }
        if ('' !== $query) {
            $qb->andWhere('p.reference LIKE :q OR p.name LIKE :q OR p.domainName LIKE :q')->setParameter('q', '%'.addcslashes($query, '%_').'%');
        }

        return $this->render('admin/projects/index.html.twig', [
            'projects' => $qb->getQuery()->getResult(),
            'counts' => $this->projects->countByStatus(),
            'status' => $status,
            'query' => $query,
            'statuses' => ProjectStatus::cases(),
        ]);
    }

    #[Route('/projects/{id}', name: 'admin_project', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Project $project, PaymentRepository $payments, CatalogProvider $catalog): Response
    {
        $order = $project->getOrder();
        $quote = $order?->getQuote() ?? $project->getCurrentQuote();
        $requirement = $project->getRequirement();

        return $this->render('admin/projects/show.html.twig', [
            'project' => $project,
            'order' => $order,
            'quote' => $quote,
            'requirement' => $requirement,
            'analysis' => $requirement->getAnalysis(),
            'solution' => $project->getSolution(),
            'sector' => $catalog->sector($project->getSector()),
            'payments' => null !== $order ? $payments->findBy(['order' => $order], ['id' => 'DESC']) : [],
            'transcript' => $requirement->getConversation()?->transcript(100) ?? [],
            'automation_states' => ProjectStatus::automationStates(),
        ]);
    }

    #[Route('/projects/{id}/decision/{action}', name: 'admin_project_action', requirements: ['id' => '\d+', 'action' => '[a-z_]+'], methods: ['POST'])]
    public function action(Request $request, Project $project, string $action): Response
    {
        $this->denyUnlessCsrfValid('project-'.$project->getId(), $request);
        if (!\in_array($action, self::ACTIONS, true)) {
            throw $this->createNotFoundException();
        }
        $payload = $request->getPayload();
        $message = $payload->getString('message');
        $at = ProjectStatus::tryFrom($payload->getString('at'));

        try {
            $flash = match ($action) {
                'approve' => $this->approve($project, $message),
                'request_changes' => $this->call(fn () => $this->approvals->requestChanges($project, $message), 'Changes requested: the customer has been notified.'),
                'reject' => $this->refundFlash('Project rejected.', $this->approvals->reject($project, $message, $payload->getBoolean('refund'))),
                'cancel' => $this->refundFlash('Project cancelled.', $this->approvals->cancel($project, $message, $payload->getBoolean('refund'))),
                'resume' => $this->call(fn () => $this->approvals->resume($project, $at), 'Automation resumed.'),
                'retry' => $this->call(fn () => $this->approvals->retry($project, $at), 'Retry scheduled.'),
                'pause' => $this->call(fn () => $this->approvals->pause($project), 'Automation paused: agents will stop after their current task.'),
                'unpause' => $this->call(fn () => $this->approvals->unpause($project), 'Automation unpaused.'),
            };
            $this->addFlash('success', $flash);
        } catch (IllegalTransitionException $e) {
            $this->addFlash('error', 'Not allowed now: '.implode(' ', $e->reasons ?: [$e->getMessage()]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_project', ['id' => $project->getId()]);
    }

    #[Route('/payments/{id}/confirm', name: 'admin_payment_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirmPayment(Request $request, Payment $payment, PaymentService $payments): Response
    {
        $this->denyUnlessCsrfValid('payment-confirm-'.$payment->getId(), $request);
        try {
            $payments->confirmOffline($payment, $request->getPayload()->getString('note'));
            $this->addFlash('success', \sprintf('Payment of order %s confirmed. The project is waiting for approval.', $payment->getOrder()->getNumber()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_project', ['id' => $payment->getOrder()->getProject()->getId()]);
    }

    private function approve(Project $project, string $note): string
    {
        $this->approvals->approve($project, '' !== trim($note) ? $note : null);

        return 'Project approved: the agents start working.';
    }

    private function call(callable $callback, string $message): string
    {
        $callback();

        return $message;
    }

    /**
     * @param array{refunded: bool, refund_error: ?string} $result
     */
    private function refundFlash(string $message, array $result): string
    {
        return match (true) {
            $result['refunded'] => $message.' The payment has been refunded.',
            null !== $result['refund_error'] => $message.' AUTOMATIC REFUND FAILED: refund the customer manually ('.$result['refund_error'].').',
            default => $message,
        };
    }
}
