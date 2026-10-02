<?php

declare(strict_types=1);

namespace App\Agent;

use App\Project\Enum\PipelineStep;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An AI agent of the automation pipeline. Agents are deterministic services that
 * may use an AI provider for content, but every result is produced or verified by
 * code: an agent never declares success without doing (and checking) the work.
 *
 * Operations must be idempotent (same project + operation = same external resources).
 */
#[AutoconfigureTag('mzian.agent')]
interface AgentInterface
{
    public static function getCode(): string;

    public static function step(): PipelineStep;

    /**
     * Permissions the operation needs (checked against Admin > Agents before running).
     *
     * @return list<string>
     */
    public static function requiredPermissions(string $operation): array;

    /**
     * @throws Exception\AgentException
     * @throws \App\Provider\Exception\ProviderException
     */
    public function execute(AgentContext $context): AgentResult;
}
