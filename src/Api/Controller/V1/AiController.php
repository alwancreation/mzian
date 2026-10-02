<?php

declare(strict_types=1);

namespace App\Api\Controller\V1;

use App\AI\Analysis\RequirementAnalyzer;
use App\AI\Analysis\RequirementInput;
use App\AI\Analysis\TextSignalExtractor;
use App\Api\Dto\AnalyzeRequest;
use App\Catalog\Questionnaire\InvalidAnswerException;
use App\Catalog\Questionnaire\QuestionnaireEngine;
use App\Catalog\Service\CatalogProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/ai')]
final class AiController extends AbstractController
{
    /**
     * Stateless analysis: {"business_type", "answers", "description"} -> structured analysis.
     */
    #[Route('/analyze', name: 'api_ai_analyze', methods: ['POST'])]
    public function analyze(
        #[MapRequestPayload] AnalyzeRequest $payload,
        Request $request,
        RequirementAnalyzer $analyzer,
        QuestionnaireEngine $questionnaire,
        CatalogProvider $catalog,
        TextSignalExtractor $extractor,
        RateLimiterFactoryInterface $aiAnalysisLimiter,
    ): JsonResponse {
        $limit = $aiAnalysisLimiter->create('api-'.($request->getClientIp() ?? 'unknown'))->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many analysis requests.');
        }

        $sector = null !== $payload->businessType ? $catalog->sector($payload->businessType) : null;
        if (null !== $payload->businessType && null === $sector) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown business_type "%s".', $payload->businessType));
        }

        // Only answers to known, visible questions are kept, normalized like in the web questionnaire.
        $answers = [];
        foreach ($payload->answers as $code => $raw) {
            $question = $questionnaire->findVisible($sector, $answers + $payload->answers, (string) $code);
            if (null === $question) {
                continue;
            }
            try {
                $answers[(string) $code] = $questionnaire->normalizeAnswer($question, $raw);
            } catch (InvalidAnswerException) {
                throw new UnprocessableEntityHttpException(\sprintf('Invalid answer for "%s".', $code));
            }
        }

        $signals = $extractor->extract($payload->description);
        $analysis = $analyzer->analyze(new RequirementInput(
            $sector?->getCode() ?? $signals->sector,
            $answers,
            $payload->description,
            $questionnaire->featuresFrom($sector, $answers),
            [],
            [],
            $payload->businessName,
            $payload->city ?? $signals->city,
            null,
            $signals->numbers,
            $payload->locale,
        ));

        return $this->json($analysis->toSchemaArray() + ['engine' => $analysis->engine, 'fallback_used' => $analysis->fallbackUsed]);
    }
}
