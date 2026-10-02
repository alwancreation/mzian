<?php

declare(strict_types=1);

namespace App\Development\Content;

use App\AI\AIGateway;
use App\AI\Dto\AIMessage;
use App\AI\Dto\AIRequest;
use App\AI\Exception\AIException;
use App\AI\Mock\MockContentGenerator;
use App\AI\Schema\JsonSchemaValidator;
use App\Catalog\Service\CatalogProvider;
use App\Project\Entity\Project;
use App\Provider\Exception\ProviderException;
use Psr\Log\LoggerInterface;

/**
 * Website copy of a customer application (tagline, about, services, SEO), written by
 * the configured AI provider and validated against the content_generation schema.
 * Falls back to the deterministic writer when the AI is unavailable. The AI never
 * receives credentials or payment data: only public business information.
 */
final readonly class ContentWriter
{
    public const TASK = 'content_generation';

    public function __construct(
        private AIGateway $gateway,
        private JsonSchemaValidator $schemas,
        private MockContentGenerator $fallback,
        private CatalogProvider $catalog,
        private LoggerInterface $aiLogger,
    ) {
    }

    /**
     * @return array{content: array<string, mixed>, usage: array<string, mixed>, fallback: bool}
     */
    public function write(Project $project, string $locale): array
    {
        $sector = $this->catalog->sector($project->getSector());
        $business = [
            'name' => $project->getBusinessName() ?? $project->getName(),
            'sector_name' => $sector?->getName($locale) ?? (string) $project->getSector(),
            'city' => $project->getRequirement()->getCity(),
            'locale' => $locale,
            'features' => array_map(fn (string $code) => $project->getSolution()?->getFeature($code)?->getName($locale) ?? str_replace('_', ' ', $code), \array_slice($project->getFeatures(), 0, 6)),
            'solution' => $project->getSolution()?->getName($locale),
            'description' => mb_substr((string) $project->getRequirement()->getDescription(), 0, 1000),
        ];
        $request = new AIRequest(
            self::TASK,
            'You write the website copy of a small business, in the language "'.$locale.'". Be concrete, warm and honest: never invent awards, figures, years, prices or customer reviews. Use only the facts given. Answer with JSON matching the schema.',
            [AIMessage::user((string) json_encode($business, \JSON_UNESCAPED_UNICODE))],
            $this->schemas->schema(self::TASK),
            'website_copy',
            1200,
            0.5,
            ['business' => $business],
        );

        try {
            $response = $this->gateway->complete($request, $project);

            return ['content' => (array) $response->data, 'usage' => $response->usage(), 'fallback' => false];
        } catch (AIException|ProviderException $e) {
            $this->aiLogger->warning('Website copy fell back to the deterministic writer', ['project' => $project->getReference(), 'reason' => $e->getMessage()]);

            return ['content' => $this->fallback->generate($business), 'usage' => ['simulated' => true, 'cost_cents' => 0], 'fallback' => true];
        }
    }
}
