<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\AI\Conversation\ConversationAssistant;
use App\Provider\Enum\ProviderType;
use App\Provider\ProviderRegistry;
use App\Requirement\Entity\Conversation;
use App\Requirement\Repository\ConversationRepository;
use App\Requirement\Service\RequirementAccess;
use App\Security\Entity\User;
use App\Shared\Controller\CsrfGuardTrait;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Describe your project" AI chat. Works without JavaScript (form POST + redirect);
 * app.js enhances it with fetch() and JSON responses.
 */
final class ChatController extends AbstractController
{
    use CsrfGuardTrait;

    private const SESSION_KEY = 'mzian_conversation';

    public function __construct(
        private readonly ConversationAssistant $assistant,
        private readonly RequirementAccess $access,
        private readonly TranslatorInterface $translator,
        private readonly ConversationRepository $conversations,
    ) {
    }

    #[Route(['fr' => '/fr/chat', 'en' => '/en/chat', 'ar' => '/ar/chat'], name: 'chat', methods: ['GET'])]
    public function chat(Request $request, ProviderRegistry $providers): Response
    {
        $conversation = $this->currentConversation($request);
        if (null === $conversation || $request->query->getBoolean('new')) {
            $user = $this->getUser();
            $sector = $request->query->getString('sector') ?: null;
            $conversation = $this->assistant->start($request->getLocale(), $user instanceof User ? $user->getCustomer() : null, $sector);
            $this->access->grant($this->assistant->requirementFor($conversation));
            $request->getSession()->set(self::SESSION_KEY, $conversation->getToken());

            return $this->redirectToRoute('chat');
        }

        $simulated = false;
        try {
            $simulated = $providers->resolve(ProviderType::Ai)->isMock();
        } catch (\Throwable) {
        }

        return $this->render('chat/index.html.twig', [
            'conversation' => $conversation,
            'requirement' => $this->assistant->requirementFor($conversation),
            'ready' => (bool) ($conversation->getContext()['ready'] ?? false),
            'simulated' => $simulated,
        ]);
    }

    #[Route(['fr' => '/fr/chat/{token}/message', 'en' => '/en/chat/{token}/message', 'ar' => '/ar/chat/{token}/message'], name: 'chat_message', requirements: ['token' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function message(Request $request, #[MapEntity(mapping: ['token' => 'token'])] Conversation $conversation, RateLimiterFactoryInterface $chatMessageLimiter): Response
    {
        $this->denyUnlessAllowed($conversation);
        $this->denyUnlessCsrfValid('chat-'.$conversation->getToken(), $request);
        $wantsJson = str_contains((string) $request->headers->get('Accept'), 'application/json');

        if (!$chatMessageLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $wantsJson
                ? new JsonResponse(['error' => $this->translator->trans('error.too_many_requests')], 429)
                : $this->redirectWithFlash('error', 'error.too_many_requests');
        }
        $content = trim($request->getPayload()->getString('message'));
        if ('' === $content) {
            return $wantsJson ? new JsonResponse(['error' => $this->translator->trans('chat.empty')], 422) : $this->redirectToRoute('chat');
        }

        $turn = $this->assistant->handle($conversation, $content);

        if ($wantsJson) {
            return new JsonResponse([
                'reply' => $turn->reply,
                'ready' => $turn->ready,
                'summaryHtmlUrl' => $this->generateUrl('chat_summary', ['token' => $conversation->getToken()]),
            ]);
        }

        return $this->redirectToRoute('chat');
    }

    #[Route(['fr' => '/fr/chat/{token}/resume', 'en' => '/en/chat/{token}/summary', 'ar' => '/ar/chat/{token}/summary'], name: 'chat_summary', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function summary(#[MapEntity(mapping: ['token' => 'token'])] Conversation $conversation): Response
    {
        $this->denyUnlessAllowed($conversation);

        return $this->render('chat/_summary.html.twig', ['requirement' => $this->assistant->requirementFor($conversation)]);
    }

    #[Route(['fr' => '/fr/chat/{token}/proposition', 'en' => '/en/chat/{token}/proposal', 'ar' => '/ar/chat/{token}/proposal'], name: 'chat_proposal', requirements: ['token' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function proposal(Request $request, #[MapEntity(mapping: ['token' => 'token'])] Conversation $conversation): Response
    {
        $this->denyUnlessAllowed($conversation);
        $this->denyUnlessCsrfValid('chat-proposal-'.$conversation->getToken(), $request);
        $requirement = $this->assistant->requirementFor($conversation);
        if (null === $requirement->getSector()) {
            return $this->redirectWithFlash('warning', 'chat.sector_needed');
        }

        // Continue with the common funnel: project details -> contact -> summary -> analysis.
        return $this->redirectToRoute('start_details', ['token' => $requirement->getToken()]);
    }

    private function currentConversation(Request $request): ?Conversation
    {
        $token = $request->getSession()->get(self::SESSION_KEY);
        if (!\is_string($token)) {
            return null;
        }
        $conversation = $this->conversations->findOneBy(['token' => $token]);

        return null !== $conversation && $conversation->isOpen() && $this->access->canAccess($this->assistant->requirementFor($conversation)) ? $conversation : null;
    }

    private function denyUnlessAllowed(Conversation $conversation): void
    {
        $this->access->denyUnlessAccessible($this->assistant->requirementFor($conversation));
    }

    private function redirectWithFlash(string $type, string $key): Response
    {
        $this->addFlash($type, $this->translator->trans($key));

        return $this->redirectToRoute('chat');
    }
}
