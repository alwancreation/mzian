<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Entity\ApiToken;
use App\Security\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Authenticates "Authorization: Bearer mzn_..." headers on /api/*.
 * Tokens are looked up by their SHA-256 hash; the clear value is never stored.
 */
final readonly class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ApiTokenRepository $tokens,
        private EntityManagerInterface $em,
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        if (!str_starts_with($accessToken, ApiToken::PREFIX)) {
            throw new BadCredentialsException('Invalid API token.');
        }

        $token = $this->tokens->findOneBy(['tokenHash' => ApiToken::hash($accessToken)]);
        if (null === $token || !$token->isValid()) {
            throw new BadCredentialsException('Invalid API token.');
        }

        $token->markUsed();
        $this->em->flush();

        return new UserBadge($token->getUser()->getUserIdentifier(), static fn () => $token->getUser());
    }
}
