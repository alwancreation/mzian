<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Lead\Entity\Lead;
use App\Lead\Enum\LeadStatus;
use App\Lead\Service\LeadService;
use App\Project\Entity\Project;
use App\Project\Enum\ProjectStatus;
use App\Project\Repository\ProjectRepository;
use App\Requirement\Entity\Requirement;
use App\Requirement\Repository\RequirementRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Controller\CsrfGuardTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin > Leads (one lead: attribution, activity, requirements, projects) and
 * Admin > AI recommendations (what the analyzer recommended, how confident it
 * was, when it fell back to the rules, and what became of it).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', defaults: ['_locale' => 'en'])]
final class LeadAdminController extends AbstractController
{
    use CsrfGuardTrait;

    private const SOLD = [ProjectStatus::Ordered, ProjectStatus::Paid, ProjectStatus::PendingAdminApproval, ProjectStatus::ChangesRequested, ProjectStatus::Approved, ProjectStatus::Provisioning, ProjectStatus::HostingReady, ProjectStatus::DomainReady, ProjectStatus::Development, ProjectStatus::Testing, ProjectStatus::Deploying, ProjectStatus::Deployed, ProjectStatus::Qa, ProjectStatus::Delivery, ProjectStatus::Completed, ProjectStatus::WaitingAdminApproval, ProjectStatus::Failed];

    public function __construct(
        private readonly RequirementRepository $requirements,
        private readonly ProjectRepository $projects,
    ) {
    }

    #[Route('/leads/{id}', name: 'admin_lead', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lead(Lead $lead): Response
    {
        return $this->render('admin/leads/show.html.twig', [
            'lead' => $lead,
            'requirements' => $this->requirements->findBy(['lead' => $lead], ['id' => 'DESC'], 20),
            'projects' => $this->projects->findBy(['lead' => $lead], ['id' => 'DESC'], 20),
            'statuses' => LeadStatus::cases(),
        ]);
    }

    #[Route('/leads/{id}/activity', name: 'admin_lead_activity', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function activity(Request $request, Lead $lead, LeadService $leads, AuditLogger $audit, EntityManagerInterface $em): Response
    {
        $this->denyUnlessCsrfValid('lead-'.$lead->getId(), $request);
        $payload = $request->getPayload();
        $note = mb_substr(trim($payload->getString('note')), 0, 2000);
        $lost = $payload->getBoolean('lost');
        if ('' === $note) {
            $this->addFlash('error', 'Write a note.');

            return $this->redirectToRoute('admin_lead', ['id' => $lead->getId()]);
        }
        $old = $lead->getStatus();
        $leads->track($lead, $lost ? 'lost' : 'note', $note, ['by' => $this->getUser()?->getUserIdentifier()], $lost ? LeadStatus::Lost : null);
        if ($lost) {
            $audit->log('lead.lost', $lead, ['status' => $old->value], ['status' => $lead->getStatus()->value], ['reason' => $note]);
        }
        $em->flush();
        $this->addFlash('success', $lost ? 'Lead marked as lost.' : 'Note added.');

        return $this->redirectToRoute('admin_lead', ['id' => $lead->getId()]);
    }

    #[Route('/ai-recommendations', name: 'admin_ai_recommendations', methods: ['GET'])]
    public function recommendations(Request $request): Response
    {
        $qb = $this->requirements->createQueryBuilder('r')
            ->leftJoin('r.recommendedSolution', 's')->addSelect('s')
            ->leftJoin('r.lead', 'l')->addSelect('l')
            ->andWhere('r.analysis IS NOT NULL')
            ->orderBy('r.analyzedAt', 'DESC')
            ->setMaxResults(200);
        if ('' !== ($solution = $request->query->getString('solution'))) {
            $qb->andWhere('s.code = :solution')->setParameter('solution', $solution);
        }
        /** @var list<Requirement> $analyzed */
        $analyzed = $qb->getQuery()->getResult();

        $projects = [];
        if ([] !== $analyzed) {
            /** @var list<Project> $found */
            $found = $this->projects->findBy(['requirement' => $analyzed]);
            foreach ($found as $project) {
                $projects[$project->getRequirement()->getId()] = $project;
            }
        }

        $stats = ['analyzed' => \count($analyzed), 'fallback' => 0, 'confidence' => 0.0, 'quoted' => 0, 'sold' => 0, 'by_solution' => [], 'unsupported' => []];
        foreach ($analyzed as $requirement) {
            $analysis = $requirement->getAnalysis() ?? [];
            $stats['fallback'] += true === ($analysis['fallback_used'] ?? false) ? 1 : 0;
            $stats['confidence'] += (float) ($analysis['confidence'] ?? 0);
            $code = $requirement->getRecommendedSolution()?->getCode() ?? 'none';
            $stats['by_solution'][$code] ??= ['count' => 0, 'sold' => 0];
            ++$stats['by_solution'][$code]['count'];
            foreach ((array) ($analysis['unsupported_features'] ?? []) as $feature) {
                $feature = mb_strtolower(trim((string) $feature));
                $stats['unsupported'][$feature] = ($stats['unsupported'][$feature] ?? 0) + 1;
            }
            $project = $projects[$requirement->getId()] ?? null;
            if (null !== $project) {
                ++$stats['quoted'];
                if (\in_array($project->getStatus(), self::SOLD, true)) {
                    ++$stats['sold'];
                    ++$stats['by_solution'][$code]['sold'];
                }
            }
        }
        $stats['confidence'] = [] !== $analyzed ? $stats['confidence'] / \count($analyzed) : 0.0;
        arsort($stats['unsupported']);
        $stats['unsupported'] = \array_slice($stats['unsupported'], 0, 15, true);
        uasort($stats['by_solution'], static fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $this->render('admin/leads/recommendations.html.twig', [
            'requirements' => $analyzed,
            'projects' => $projects,
            'stats' => $stats,
            'solution' => $solution,
        ]);
    }
}
