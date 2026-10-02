<?php

declare(strict_types=1);

namespace App\AI\Analysis;

use App\AI\AIGateway;
use App\AI\Dto\AIMessage;
use App\AI\Dto\AIRequest;
use App\AI\Exception\AIException;
use App\AI\Exception\InvalidAIOutputException;
use App\AI\Schema\JsonSchemaValidator;
use App\Catalog\Service\CatalogProvider;
use App\Project\Entity\Project;
use App\Provider\Exception\ProviderException;
use Psr\Log\LoggerInterface;

/**
 * RequirementAnalyzer: turns a customer request into a validated RequirementAnalysis.
 *
 *   input -> AI provider (JSON schema) -> schema validation -> catalog validation
 *         -> merge with explicit answers -> RequirementAnalysis
 *
 * When the AI is unavailable or its output cannot be trusted, the deterministic
 * rule-based analysis is used instead (and flagged as a fallback).
 */
final readonly class RequirementAnalyzer
{
    public const TASK = 'requirement_analysis';

    public function __construct(
        private AIGateway $gateway,
        private PromptBuilder $prompts,
        private JsonSchemaValidator $schemas,
        private RuleBasedAnalyzer $rules,
        private CatalogProvider $catalog,
        private LoggerInterface $aiLogger,
    ) {
    }

    public function analyze(RequirementInput $input, ?Project $project = null): RequirementAnalysis
    {
        $request = new AIRequest(
            self::TASK,
            $this->prompts->analysisSystemPrompt(),
            [AIMessage::user($this->prompts->analysisUserMessage($input))],
            $this->schemas->schema(self::TASK),
            'requirement_analysis',
            1500,
            0.1,
            ['input' => $input->toArray()],
        );

        try {
            $response = $this->gateway->complete($request, $project);
            $data = $this->validateAgainstCatalog((array) $response->data, $input);

            return RequirementAnalysis::fromArray($data, $response->simulated ? 'rules' : 'ai:'.$response->provider, false, null, $response->usage());
        } catch (InvalidAIOutputException|AIException|ProviderException $e) {
            $this->aiLogger->warning('Requirement analysis fell back to the rule-based engine', ['reason' => $e->getMessage()]);
            $data = $this->rules->analyze($input);

            return RequirementAnalysis::fromArray($data, 'rules', true, mb_substr($e->getMessage(), 0, 300));
        }
    }

    /**
     * Business validation of a schema-valid AI output: the solution must exist, features
     * must belong to it, explicit customer requests are never silently dropped and
     * explicit refusals are respected.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws InvalidAIOutputException
     */
    private function validateAgainstCatalog(array $data, RequirementInput $input): array
    {
        $solution = $this->catalog->solution((string) ($data['solution'] ?? ''));
        if (null === $solution) {
            throw new InvalidAIOutputException('The AI recommended an unknown or disabled solution', [(string) ($data['solution'] ?? '')]);
        }

        $requested = array_values(array_filter(
            array_unique(array_merge((array) $data['features'], $input->requestedFeatures)),
            static fn (string $f) => !str_starts_with($f, 'no_') && !\in_array($f, $input->refusedFeatures, true),
        ));
        [$features, $unsupported] = $this->rules->mapFeatures($solution, $requested);

        $data['features'] = $features;
        $data['unsupported_features'] = array_values(array_unique(array_merge(
            $unsupported,
            array_values(array_filter((array) $data['unsupported_features'], static fn (string $f) => !\in_array($f, $features, true))),
        )));
        $data['solution_type'] = RuleBasedAnalyzer::solutionType($solution->getCategory());
        // Keep the estimate realistic: never below the template's own lead time, at most x3.
        $base = $solution->getEstimatedDevelopmentDays();
        $data['estimated_development_days'] = max($base, min($base * 3, (int) $data['estimated_development_days']));
        $hosting = $solution->getHostingRequirements();
        $data['hosting_requirements']['storage_gb'] = max((int) $data['hosting_requirements']['storage_gb'], (int) ($hosting['storage_gb'] ?? 1));
        $data['hosting_requirements']['database'] = (bool) $data['hosting_requirements']['database'] || (bool) ($hosting['database'] ?? false);
        $data['domain_required'] = (bool) $data['domain_required'] || $solution->isDomainRequired();

        return $data;
    }
}
