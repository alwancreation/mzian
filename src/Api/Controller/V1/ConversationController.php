<?php

declare(strict_types=1);

namespace App\Api\Controller\V1;

use App\AI\Conversation\ConversationAssistant;
use App\Api\Dto\ConversationRequest;
use App\Api\Dto\MessageRequest;
use App\Requirement\Entity\Conversation;
use App\Requirement\Entity\RequirementItem;
use App\Security\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Conversations are addressed by their secret token (returned at creation).
 */
#[Route('/api/v1/conversations')]
final class ConversationController extends AbstractController
{
    public function __construct(
        private readonly ConversationAssistant $assistant,
        private readonly RateLimiterFactoryInterface $chatMessageLimiter,
    ) {
    }

    #[Route('', name: 'api_conversation_create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] ?ConversationRequest $payload = null): JsonResponse
    {
        $payload ??= new ConversationRequest();
        $user = $this->getUser();
        $conversation = $this->assistant->start($payload->locale, $user instanceof User ? $user->getCustomer() : null);
        if (null !== $payload->message && '' !== trim($payload->message)) {
            $this->throttle($request);
            $this->assistant->handle($conversation, $payload->message);
        }

        return $this->json($this->serialize($conversation), 201);
    }

    #[Route('/{token}', name: 'api_conversation_show', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['token' => 'token'])] Conversation $conversation): JsonResponse
    {
        return $this->json($this->serialize($conversation));
    }

    #[Route('/{token}/messages', name: 'api_conversation_message', requirements: ['token' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function message(Request $request, #[MapEntity(mapping: ['token' => 'token'])] Conversation $conversation, #[MapRequestPayload] MessageRequest $payload): JsonResponse
    {
        $this->throttle($request);
        $turn = $this->assistant->handle($conversation, $payload->content);

        return $this->json(['reply' => $turn->reply, 'ready' => $turn->ready, 'next_question' => $turn->nextQuestionKey, 'engine' => $turn->engine] + $this->serialize($conversation));
    }

    private function throttle(Request $request): void
    {
        $limit = $this->chatMessageLimiter->create('api-'.($request->getClientIp() ?? 'unknown'))->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many messages.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Conversation $conversation): array
    {
        $requirement = $this->assistant->requirementFor($conversation);

        return [
            'token' => $conversation->getToken(),
            'locale' => $conversation->getLocale(),
            'ready' => (bool) ($conversation->getContext()['ready'] ?? false),
            'messages' => $conversation->transcript(50),
            'requirement' => [
                'sector' => $requirement->getSector(),
                'city' => $requirement->getCity(),
                'items' => array_values(array_map(static fn (RequirementItem $i) => ['key' => $i->getKey(), 'label' => $i->getLabel(), 'value' => $i->getValue(), 'source' => $i->getSource()->value], $requirement->getItems()->toArray())),
            ],
        ];
    }
}
