<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Entity\AuditLog;
use App\Shared\Security\Actor;
use App\Shared\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records who did what, when, on which entity (old/new values, IP, metadata).
 * Values are sanitized: keys that look like secrets are always masked.
 */
final readonly class AuditLogger
{
    private const SENSITIVE_KEYS = '/pass(word)?|secret|token|api[_-]?key|credential|authorization|private/i';

    public function __construct(
        private EntityManagerInterface $em,
        private CurrentActor $currentActor,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Persists the entry; it is written with the caller's next flush (same transaction).
     *
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     * @param array<string, mixed>      $metadata
     */
    public function log(
        string $action,
        ?object $entity = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        array $metadata = [],
        ?string $message = null,
        ?Actor $actor = null,
    ): AuditLog {
        $actor ??= $this->currentActor->get();
        $request = $this->requestStack->getMainRequest();

        $entry = new AuditLog(
            $actor->type,
            $actor->id,
            $actor->name,
            $action,
            null !== $entity ? self::entityType($entity) : null,
            null !== $entity ? self::entityId($entity) : null,
            null !== $oldValue ? self::sanitize($oldValue) : null,
            null !== $newValue ? self::sanitize($newValue) : null,
            $request?->getClientIp(),
            $request?->headers->get('User-Agent'),
            self::sanitize($metadata),
            $message,
        );
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    public static function sanitize(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($key) && preg_match(self::SENSITIVE_KEYS, $key)) {
                $values[$key] = '***';
            } elseif (\is_array($value)) {
                $values[$key] = self::sanitize($value);
            } elseif ($value instanceof \BackedEnum) {
                $values[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $values[$key] = $value->format(\DATE_ATOM);
            } elseif (\is_object($value)) {
                $values[$key] = self::entityType($value).'#'.(self::entityId($value) ?? '?');
            }
        }

        return $values;
    }

    private static function entityType(object $entity): string
    {
        $class = $entity::class;
        // Doctrine proxies: keep the real class short name.
        if (false !== $pos = strrpos($class, '\\__CG__\\')) {
            $class = substr($class, $pos + 8);
        }

        return substr($class, (int) strrpos($class, '\\') + 1);
    }

    private static function entityId(object $entity): ?string
    {
        if (method_exists($entity, 'getId')) {
            $id = $entity->getId();

            return null !== $id ? (string) $id : null;
        }

        return null;
    }
}
