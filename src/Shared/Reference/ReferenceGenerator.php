<?php

declare(strict_types=1);

namespace App\Shared\Reference;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Human friendly, non-guessable business references.
 *
 *   project  MZ-2610-7KQ4XT     quote  Q-2610-9F2KD7
 *   order    ORD-2610-H3M8ZP    invoice INV-2026-00042 (sequential per year, legal requirement)
 */
final readonly class ReferenceGenerator
{
    /** No 0/O/1/I to avoid confusion when read on the phone. */
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function project(): string
    {
        return $this->random('MZ');
    }

    public function quote(): string
    {
        return $this->random('Q');
    }

    public function order(): string
    {
        return $this->random('ORD');
    }

    /**
     * Next invoice number of the year. Invoice numbers must be sequential without gaps:
     * the unique index on invoice.number guarantees no duplicate under concurrency.
     */
    public function invoice(\DateTimeImmutable $date = new \DateTimeImmutable()): string
    {
        $prefix = 'INV-'.$date->format('Y').'-';
        $last = $this->em->createQuery('SELECT MAX(i.number) FROM App\Billing\Entity\Invoice i WHERE i.number LIKE :prefix')
            ->setParameter('prefix', $prefix.'%')
            ->getSingleScalarResult();
        $next = null === $last ? 1 : (int) substr((string) $last, \strlen($prefix)) + 1;

        return $prefix.str_pad((string) $next, 5, '0', \STR_PAD_LEFT);
    }

    private function random(string $prefix): string
    {
        $suffix = '';
        for ($i = 0; $i < 6; ++$i) {
            $suffix .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return \sprintf('%s-%s-%s', $prefix, date('ym'), $suffix);
    }
}
