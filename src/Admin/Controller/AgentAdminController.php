<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Agent\AgentPermission;
use App\Agent\Entity\Agent;
use App\Agent\Entity\AgentRun;
use App\Agent\Repository\AgentRepository;
use App\Agent\Repository\AgentRunRepository;
use App\Agent\Repository\AgentTaskRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Controller\CsrfGuardTrait;
use App\Testing\Entity\TestRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin > Agents: configuration (enable, attempts, permissions: super admins
 * only), activity, run logs and test reports.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', defaults: ['_locale' => 'en'])]
final class AgentAdminController extends AbstractController
{
    use CsrfGuardTrait;

    #[Route('/agents', name: 'admin_agents', methods: ['GET'])]
    public function index(AgentRepository $agents, AgentRunRepository $runs): Response
    {
        return $this->render('admin/agents/index.html.twig', [
            'agents' => $agents->findBy([], ['id' => 'ASC']),
            'runs' => $runs->findBy([], ['id' => 'DESC'], 50),
            'permissions' => AgentPermission::ALL,
            'forbidden' => AgentPermission::FORBIDDEN,
        ]);
    }

    #[Route('/agents/{id}', name: 'admin_agent_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function update(Request $request, Agent $agent, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $this->denyUnlessCsrfValid('agent-'.$agent->getId(), $request);
        $payload = $request->getPayload();
        $old = ['enabled' => $agent->isEnabled(), 'permissions' => $agent->getPermissions(), 'max_attempts' => $agent->getMaxAttempts()];
        $permissions = array_values(array_intersect(AgentPermission::ALL, (array) ($request->request->all()['permissions'] ?? [])));
        try {
            $agent->update($agent->getName(), $agent->getDescription(), $permissions, max(1, min(10, $payload->getInt('max_attempts', 3))));
            $agent->setEnabled($payload->getBoolean('enabled'));
            $audit->log('agent.updated', $agent, $old, ['enabled' => $agent->isEnabled(), 'permissions' => $permissions, 'max_attempts' => $agent->getMaxAttempts()]);
            $em->flush();
            $this->addFlash('success', $agent->getName().' updated.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_agents');
    }

    #[Route('/agent-runs/{id}', name: 'admin_agent_run', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function run(AgentRun $run, AgentTaskRepository $tasks): Response
    {
        return $this->render('admin/agents/run.html.twig', ['run' => $run, 'tasks' => $tasks->findBy(['run' => $run])]);
    }

    #[Route('/test-runs/{id}', name: 'admin_test_run', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function testRun(TestRun $testRun): Response
    {
        return $this->render('admin/agents/test_run.html.twig', ['test_run' => $testRun, 'report' => $testRun->toReport()]);
    }
}
