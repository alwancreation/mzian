<?php

declare(strict_types=1);

namespace App\Agent\MessageHandler;

use App\Agent\AgentOrchestrator;
use App\Agent\Message\AbstractPipelineMessage;
use App\Agent\Message\CreateProjectMessage;
use App\Agent\Message\DeployApplicationMessage;
use App\Agent\Message\GenerateApplicationMessage;
use App\Agent\Message\ProvisionHostingMessage;
use App\Agent\Message\RegisterDomainMessage;
use App\Agent\Message\RunQaMessage;
use App\Agent\Message\RunTestsMessage;
use App\Agent\Message\SendDeliveryMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Pipeline jobs → AgentOrchestrator (locking, permissions, budget, retries, logs).
 */
final readonly class PipelineMessageHandler
{
    public function __construct(private AgentOrchestrator $orchestrator)
    {
    }

    #[AsMessageHandler(handles: ProvisionHostingMessage::class)]
    #[AsMessageHandler(handles: RegisterDomainMessage::class)]
    #[AsMessageHandler(handles: CreateProjectMessage::class)]
    #[AsMessageHandler(handles: GenerateApplicationMessage::class)]
    #[AsMessageHandler(handles: RunTestsMessage::class)]
    #[AsMessageHandler(handles: DeployApplicationMessage::class)]
    #[AsMessageHandler(handles: RunQaMessage::class)]
    #[AsMessageHandler(handles: SendDeliveryMessage::class)]
    public function __invoke(AbstractPipelineMessage $message): void
    {
        $this->orchestrator->handle($message);
    }
}
