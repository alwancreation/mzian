<?php

declare(strict_types=1);

namespace App\Billing\Payment;

use App\Billing\Service\PaymentService;
use App\Provider\Enum\ProviderType;
use App\Provider\ProviderRegistry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Entity\WebhookEvent;
use App\Shared\Repository\WebhookEventRepository;
use App\Shared\Security\Actor;
use App\Shared\Security\CurrentActor;
use App\Shared\Webhook\InvalidWebhookException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payment webhooks: authenticate (driver signature check) → deduplicate
 * (provider + event id) → apply as the "webhook" actor → record the outcome.
 * Returns an HTTP status the gateway understands (5xx = please retry).
 */
final readonly class PaymentWebhookProcessor
{
    public function __construct(
        private ProviderRegistry $providers,
        private PaymentService $payments,
        private WebhookEventRepository $events,
        private CurrentActor $actor,
        private AuditLogger $audit,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{status: int, result: string}
     */
    public function process(string $providerCode, Request $request): array
    {
        $provider = $this->providers->findByCode($providerCode);
        if (null === $provider || ProviderType::Payment !== $provider->getType() || !$provider->isEnabled()) {
            return ['status' => Response::HTTP_NOT_FOUND, 'result' => 'unknown_provider'];
        }

        try {
            $event = $this->payments->driver($provider)->parseWebhook($provider, $request);
        } catch (InvalidWebhookException $e) {
            // The payload is never logged: it is untrusted.
            $this->logger->warning('Rejected payment webhook', ['provider' => $providerCode, 'reason' => $e->getMessage(), 'ip' => $request->getClientIp()]);
            $this->audit->log('webhook.rejected', null, null, null, ['channel' => 'payment', 'provider' => $providerCode, 'reason' => $e->getMessage()], null, Actor::webhook($providerCode));
            $this->em->flush();

            return ['status' => Response::HTTP_UNAUTHORIZED, 'result' => 'invalid_signature'];
        }

        $record = $this->events->findOneBy(['provider' => $providerCode, 'eventId' => $event->eventId]);
        if (null !== $record && \in_array($record->getStatus(), [WebhookEvent::STATUS_PROCESSED, WebhookEvent::STATUS_IGNORED], true)) {
            return ['status' => Response::HTTP_OK, 'result' => 'duplicate'];
        }
        if (null === $record) {
            $record = new WebhookEvent('payment', $providerCode, $event->eventId, $event->providerEventType, [
                'type' => $event->type,
                'payment_key' => $event->paymentKey,
                'provider_reference' => $event->providerReference,
                'amount' => $event->amount,
                'currency' => $event->currency,
            ] + $event->summary);
            $this->em->persist($record);
            $this->em->flush();
        }

        try {
            $payment = $this->actor->runAs(Actor::webhook($providerCode), fn () => $this->payments->handle($provider, $event));
            $record->markProcessed(null === $payment ? WebhookEvent::STATUS_IGNORED : WebhookEvent::STATUS_PROCESSED);
            $this->em->flush();

            return ['status' => Response::HTTP_OK, 'result' => null === $payment ? 'ignored' : 'processed'];
        } catch (InvalidWebhookException $e) {
            $record->markFailed($e->getMessage());
            $this->em->flush();

            return ['status' => Response::HTTP_BAD_REQUEST, 'result' => 'rejected'];
        } catch (\Throwable $e) {
            $this->logger->error('Payment webhook processing failed', ['provider' => $providerCode, 'event' => $event->eventId, 'exception' => $e]);
            if ($this->em->isOpen()) {
                $record->markFailed($e->getMessage());
                $this->em->flush();
            }

            return ['status' => Response::HTTP_INTERNAL_SERVER_ERROR, 'result' => 'error'];
        }
    }
}
