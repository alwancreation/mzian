<?php

declare(strict_types=1);

namespace App\Api\Controller\V1;

use App\AI\Conversation\ConversationAssistant;
use App\Api\Dto\QuoteRequest;
use App\Api\Presenter\CommercePresenter;
use App\Customer\Entity\Customer;
use App\Order\Entity\Quote;
use App\Order\Service\QuoteService;
use App\Requirement\Repository\ConversationRepository;
use App\Requirement\Service\RequirementAnalysisService;
use App\Security\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Quotes for customers authenticated with an API token.
 */
#[Route('/api/v1/quotes')]
final class QuoteController extends AbstractController
{
    #[Route('', name: 'api_quote_create', methods: ['POST'])]
    public function create(
        Request $request,
        #[MapRequestPayload] QuoteRequest $payload,
        ConversationRepository $conversations,
        ConversationAssistant $assistant,
        RequirementAnalysisService $analysis,
        QuoteService $quotes,
        RateLimiterFactoryInterface $aiAnalysisLimiter,
        EntityManagerInterface $em,
    ): JsonResponse {
        $customer = $this->customer();
        $conversation = $conversations->findOneBy(['token' => $payload->conversation]) ?? throw $this->createNotFoundException('Conversation not found.');
        $requirement = $assistant->requirementFor($conversation);
        if (null !== $requirement->getCustomer() && $requirement->getCustomer() !== $customer) {
            throw $this->createNotFoundException('Conversation not found.');
        }
        if (null === $requirement->getSector()) {
            throw new UnprocessableEntityHttpException('Not enough information yet: continue the conversation (business type unknown).');
        }
        if (!$quotes->isOpen($requirement)) {
            throw new ConflictHttpException('This request has already been ordered.');
        }
        $limit = $aiAnalysisLimiter->create('api-'.($request->getClientIp() ?? 'unknown'))->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many analyses.');
        }
        $requirement->setCustomer($customer);
        $requirement->setBusinessName($requirement->getBusinessName() ?? $customer->getCompanyName() ?? $customer->getDisplayName());
        $em->flush();

        try {
            $quote = $quotes->issue($requirement, $analysis->analyze($requirement));
        } catch (\DomainException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->json(CommercePresenter::quote($quote), 201);
    }

    #[Route('/{token}', name: 'api_quote_show', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['token' => 'token'])] Quote $quote): JsonResponse
    {
        if ($quote->getProject()->getCustomer() !== $this->customer()) {
            throw $this->createNotFoundException('Quote not found.');
        }

        return $this->json(CommercePresenter::quote($quote));
    }

    private function customer(): Customer
    {
        $user = $this->getUser();

        return ($user instanceof User ? $user->getCustomer() : null) ?? throw $this->createAccessDeniedException('A customer API token is required.');
    }
}
