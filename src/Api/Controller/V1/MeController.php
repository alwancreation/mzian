<?php

declare(strict_types=1);

namespace App\Api\Controller\V1;

use App\Security\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1')]
final class MeController extends AbstractController
{
    /**
     * Identity of the authenticated API client.
     */
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
            'roles' => $user->getRoles(),
            'customer' => null !== $user->getCustomer() ? [
                'id' => $user->getCustomer()->getId(),
                'company' => $user->getCustomer()->getCompanyName(),
            ] : null,
        ]);
    }
}
