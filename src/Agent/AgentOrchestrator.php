<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Entity\Agent;
use App\Agent\Entity\AgentRun;
use App\Agent\Entity\AgentTask;
use App\Agent\Enum\AgentRunStatus;
use App\Agent\Enum\AgentTaskStatus;
use App\Agent\Exception\AgentException;
use App\Agent\Exception\NeedsAdminException;
use App\Agent\Message\AbstractPipelineMessage;
use App\Agent\Message\CreateProjectMessage;
use App\Agent\Message\DeployApplicationMessage;
use App\Agent\Message\GenerateApplicationMessage;
use App\Agent\Message\ProvisionHostingMessage;
use App\Agent\Message\RegisterDomainMessage;
use App\Agent\Message\RunQaMessage;
use App\Agent\Message\RunTestsMessage;
use App\Agent\Message\SendDeliveryMessage;
use App\Agent\Repository\AgentRepository;
use App\Agent\Repository\AgentTaskRepository;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectTask;
use App\Project\Enum\PipelineStep;
use App\Project\Enum\ProjectTaskStatus;
use App\Project\Repository\ProjectRepository;
use App\Project\Workflow\ProjectStateMachine;
use App\Provider\Exception\ProviderException;
use App\Shared\Security\Actor;
use App\Shared\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Drives an APPROVED project through the pipeline, one Messenger job per
 * operation (hosting → domain → development → tests → deployment → QA → delivery):
 *
 *  - one job at a time per project (lock), stale or duplicated jobs are ignored;
 *  - the agent's permissions and the automation budget are checked before acting;
 *  - transient failures are retried with exponential backoff, then an
 *    administrator decides (WAITING_ADMIN_APPROVAL); permanent failures → FAILED;
 *  - every attempt is recorded (AgentRun with logs, AgentTask per operation,
 *    ProjectTask per step, state machine events).
 *
 * The orchestrator never approves, resumes or cancels: only humans do.
 */
final class AgentOrchestrator
{
    /** operation => message class, in pipeline order. */
    private const OPERATIONS = [
        'provision_hosting' => ProvisionHostingMessage::class,
        'register_domain' => RegisterDomainMessage::class,
        'create_project' => CreateProjectMessage::class,
        'generate_application' => GenerateApplicationMessage::class,
        'run_tests' => RunTestsMessage::class,
        'deploy_application' => DeployApplicationMessage::class,
        'run_qa' => RunQaMessage::class,
        'send_delivery' => SendDeliveryMessage::class,
    ];
    private const STEP_OPERATIONS = [
        'hosting' => ['provision_hosting'],
        'domain' => ['register_domain'],
        'development' => ['create_project', 'generate_application'],
        'testing' => ['run_tests'],
        'deployment' => ['deploy_application'],
        'qa' => ['run_qa'],
        'delivery' => ['send_delivery'],
    ];

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly AgentRepository $agentConfigs,
        private readonly AgentTaskRepository $agentTasks,
        #[AutowireLocator('mzian.agent', defaultIndexMethod: 'getCode')]
        private readonly ContainerInterface $agents,
        private readonly ProjectStateMachine $stateMachine,
        private readonly MessageBusInterface $bus,
        private readonly LockFactory $locks,
        private readonly CurrentActor $actor,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%mzian.agent_retry_backoff_seconds%')]
        private readonly int $backoffSeconds = 5,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function operationsOf(PipelineStep $step): array
    {
        return self::STEP_OPERATIONS[$step->value];
    }

    public static function stepOf(string $operation): PipelineStep
    {
        foreach (self::STEP_OPERATIONS as $step => $operations) {
            if (\in_array($operation, $operations, true)) {
                return PipelineStep::from($step);
            }
        }

        throw new \InvalidArgumentException('Unknown operation '.$operation);
    }

    /**
     * Queues the next job of the project, if any. Returns the operation queued.
     */
    public function kick(Project $project): ?string
    {
        if ($project->isAutomationPaused() || !$project->getStatus()->isAutomation()) {
            return null;
        }
        $operation = $this->nextOperation($project);
        if (null !== $operation) {
            $class = self::OPERATIONS[$operation];
            $this->bus->dispatch(new $class((int) $project->getId()), [new DispatchAfterCurrentBusStamp()]);
        }

        return $operation;
    }

    public function nextOperation(Project $project): ?string
    {
        $status = $project->getStatus();
        foreach (PipelineStep::ordered() as $step) {
            $task = $project->getTask($step);
            if (null !== $task && \in_array($task->getStatus(), [ProjectTaskStatus::Succeeded, ProjectTaskStatus::Skipped], true)) {
                continue;
            }
            if ($status !== $step->requiredStatus() && $status !== $step->runningStatus()) {
                return null;
            }
            foreach (self::operationsOf($step) as $operation) {
                if (AgentTaskStatus::Succeeded !== $this->agentTask($project, $operation)?->getStatus()) {
                    return $operation;
                }
            }

            return self::operationsOf($step)[\count(self::operationsOf($step)) - 1];
        }

        return null;
    }

    /**
     * After an administrator resumes or retries a project: the step matching the
     * current status and every later step run again (idempotent operations).
     */
    public function prepareRestart(Project $project): void
    {
        $restart = false;
        foreach (PipelineStep::ordered() as $step) {
            $restart = $restart || $project->getStatus() === $step->requiredStatus() || $project->getStatus() === $step->runningStatus();
            $task = $project->getTask($step);
            if (!$restart || null === $task) {
                continue;
            }
            $task->reset();
            foreach (self::operationsOf($step) as $operation) {
                $this->agentTask($project, $operation)?->supersede();
            }
        }
        $this->em->flush();
    }

    /**
     * Messenger entry point (see PipelineMessageHandler).
     */
    public function handle(AbstractPipelineMessage $message): void
    {
        $operation = $message::operation();
        $lock = $this->locks->createLock('mzian-pipeline-'.$message->projectId, 900);
        if (!$lock->acquire()) {
            $this->logger->info('Pipeline job skipped: project busy', ['project' => $message->projectId, 'operation' => $operation]);

            return;
        }
        $project = null;
        $continue = false;
        try {
            $project = $this->projects->find($message->projectId);
            if (null === $project || $project->isAutomationPaused() || $this->nextOperation($project) !== $operation) {
                $this->logger->info('Stale pipeline job ignored', ['project' => $message->projectId, 'operation' => $operation]);

                return;
            }
            $continue = $this->run($project, $operation, $message);
        } finally {
            $lock->release();
        }
        if ($continue) {
            $this->kick($project);
        }
    }

    private function run(Project $project, string $operation, AbstractPipelineMessage $message): bool
    {
        $step = self::stepOf($operation);
        $agentCode = $step->agentCode();
        $config = $this->agentConfigs->findOneBy(['code' => $agentCode]);
        $task = $project->getTask($step) ?? $this->createTask($project, $step, $config);

        $blocked = match (true) {
            null === $config || !$this->agents->has($agentCode) => \sprintf('The %s agent is not installed.', $agentCode),
            !$config->isEnabled() => \sprintf('The %s agent is disabled by an administrator.', $agentCode),
            default => null,
        };
        foreach (null === $blocked ? $this->agentService($agentCode)::requiredPermissions($operation) : [] as $permission) {
            if (null === $blocked && !$config?->isAllowed($permission)) {
                $blocked = \sprintf('The %s agent lacks the "%s" permission (Admin > Agents).', $agentCode, $permission);
            }
        }
        if (null !== $blocked) {
            $task->waitForAdmin($blocked);
            $this->asAgent($agentCode, fn () => $this->stateMachine->hold($project, $blocked, ['operation' => $operation]));

            return false;
        }

        $operations = self::operationsOf($step);
        if ($project->getStatus() === $step->requiredStatus() && null !== $step->startTransition() && $operation === $operations[0]) {
            $this->asAgent($agentCode, fn () => $this->stateMachine->apply($project, (string) $step->startTransition(), null, ['operation' => $operation], false));
        }

        $run = new AgentRun($agentCode, $project, $task, $message->attempt);
        $this->em->persist($run);
        $agentTask = $this->agentTask($project, $operation);
        if (null === $agentTask) {
            $agentTask = new AgentTask($run, $project, $operation, $this->key($project, $operation), ['attempt' => $message->attempt]);
            $this->em->persist($agentTask);
        } else {
            $agentTask->restart($run);
        }
        $task->start();
        $this->em->flush();

        try {
            /** @var AgentResult $result */
            $result = $this->asAgent($agentCode, fn () => $this->agentService($agentCode)->execute(new AgentContext($project, $task, $run, $operation, $message->attempt)));
        } catch (NeedsAdminException $e) {
            $this->stopForAdmin($project, $task, $run, $agentTask, $agentCode, $e->getMessage(), $e->metadata);

            return false;
        } catch (AgentException|ProviderException $e) {
            $this->failure($project, $task, $run, $agentTask, $agentCode, $message, $e->getMessage(), $e->retryable, $config);

            return false;
        } catch (\Throwable $e) {
            if (!$this->em->isOpen()) {
                throw $e;
            }
            $this->logger->error('Agent crashed', ['project' => $project->getReference(), 'operation' => $operation, 'exception' => $e]);
            $this->failure($project, $task, $run, $agentTask, $agentCode, $message, 'Unexpected error: '.$e->getMessage(), true, $config);

            return false;
        }

        $agentTask->succeed($result->output);
        $run->log('info', $result->summary);
        $run->finish(AgentRunStatus::Succeeded);
        $task->mergeOutput($result->output);
        if ($operation === $operations[\count($operations) - 1]) {
            $task->succeed($task->getOutput());
            if (null !== $step->completeTransition()) {
                $this->asAgent($agentCode, fn () => $this->stateMachine->apply($project, (string) $step->completeTransition(), $result->summary, [], false));
            }
        }
        $this->em->flush();

        return true;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function stopForAdmin(Project $project, ProjectTask $task, AgentRun $run, AgentTask $agentTask, string $agentCode, string $reason, array $metadata): void
    {
        $agentTask->fail($reason);
        $run->log('warning', 'Waiting for an administrator: '.$reason, $metadata);
        $run->finish(AgentRunStatus::WaitingAdmin, $reason);
        $task->waitForAdmin($reason);
        $this->asAgent($agentCode, fn () => $this->stateMachine->hold($project, $reason, $metadata));
    }

    private function failure(Project $project, ProjectTask $task, AgentRun $run, AgentTask $agentTask, string $agentCode, AbstractPipelineMessage $message, string $error, bool $retryable, ?Agent $config): void
    {
        $agentTask->fail($error);
        $run->log('error', $error);
        $maxAttempts = $config?->getMaxAttempts() ?? 3;
        if ($retryable && $message->attempt < $maxAttempts) {
            $delay = $this->backoffSeconds * (2 ** ($message->attempt - 1));
            $run->finish(AgentRunStatus::Retrying, $error);
            $task->recordRetryableFailure($error);
            $this->em->flush();
            $this->bus->dispatch($message->retry(), array_filter([$delay > 0 ? new DelayStamp($delay * 1000) : null, new DispatchAfterCurrentBusStamp()]));
            $this->logger->warning('Agent operation failed, retry scheduled', ['project' => $project->getReference(), 'operation' => $message::operation(), 'attempt' => $message->attempt, 'delay_s' => $delay]);

            return;
        }
        if ($retryable) {
            $this->stopForAdmin($project, $task, $run, $agentTask, $agentCode, \sprintf('%s failed %d times: %s', $message::operation(), $message->attempt, $error), ['operation' => $message::operation()]);

            return;
        }
        $run->finish(AgentRunStatus::Failed, $error);
        $task->fail($error);
        $this->asAgent($agentCode, fn () => $this->stateMachine->fail($project, $error, ['operation' => $message::operation()]));
    }

    private function createTask(Project $project, PipelineStep $step, ?Agent $config): ProjectTask
    {
        $task = new ProjectTask($project, $step, $config?->getMaxAttempts() ?? 3);
        $this->em->persist($task);

        return $task;
    }

    private function agentTask(Project $project, string $operation): ?AgentTask
    {
        return $this->agentTasks->findOneBy(['idempotencyKey' => $this->key($project, $operation)]);
    }

    private function key(Project $project, string $operation): string
    {
        return $project->getReference().':'.$operation;
    }

    private function agentService(string $code): AgentInterface
    {
        /* @var AgentInterface */
        return $this->agents->get($code);
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function asAgent(string $agentCode, callable $callback): mixed
    {
        return $this->actor->runAs(Actor::agent($agentCode), $callback);
    }
}
