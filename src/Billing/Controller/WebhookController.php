<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Payment\PaymentWebhookProcessor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Payment gateway notifications. Authenticated by signature (no session/CSRF),
 * deduplicated by event id. See PaymentWebhookProcessor.
 */
final class WebhookController extends AbstractController
{
    #[Route('/webhooks/payment/{provider}', name: 'webhook_payment', requirements: ['provider' => '[a-z0-9_]{2,60}'], methods: ['POST'])]
    public function payment(string $provider, Request $request, PaymentWebhookProcessor $processor): JsonResponse
    {
        $result = $processor->process($provider, $request);

        return new JsonResponse(['result' => $result['result']], $result['status']);
    }
}
