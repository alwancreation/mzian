<?php

declare(strict_types=1);

namespace App\Catalog\Service;

use App\Catalog\Entity\Question;
use App\Catalog\Entity\Sector;
use App\Catalog\Entity\Solution;
use App\Catalog\Repository\QuestionRepository;
use App\Catalog\Repository\SectorRepository;
use App\Catalog\Repository\SolutionRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read access to the catalog (sectors, solutions, questions) with Doctrine result
 * caching and per-request memoization. Call invalidate() after any catalog change.
 */
final class CatalogProvider implements ResetInterface
{
    private const TTL = 3600;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly SectorRepository $sectors,
        private readonly SolutionRepository $solutions,
        private readonly QuestionRepository $questions,
        #[Autowire(service: 'cache.catalog')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return list<Sector>
     */
    public function enabledSectors(): array
    {
        return $this->memo['sectors'] ??= $this->sectors->createQueryBuilder('s')
            ->andWhere('s.enabled = true')
            ->orderBy('s.position', 'ASC')
            ->getQuery()->enableResultCache(self::TTL, 'catalog_sectors')->getResult();
    }

    public function sector(?string $code): ?Sector
    {
        foreach ($this->enabledSectors() as $sector) {
            if ($sector->getCode() === $code) {
                return $sector;
            }
        }

        return null;
    }

    public function sectorBySlug(string $slug, string $locale): ?Sector
    {
        foreach ($this->enabledSectors() as $sector) {
            if ($sector->getSlug($locale) === $slug) {
                return $sector;
            }
        }
        // Slug of another language: the controller redirects to the right one.
        foreach ($this->enabledSectors() as $sector) {
            if (\in_array($slug, $sector->getSlugs(), true) || $sector->getSlug() === $slug) {
                return $sector;
            }
        }

        return null;
    }

    /**
     * @return list<Solution>
     */
    public function enabledSolutions(): array
    {
        return $this->memo['solutions'] ??= $this->solutions->createQueryBuilder('s')
            ->leftJoin('s.features', 'f')->addSelect('f')
            ->andWhere('s.enabled = true')
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('f.position', 'ASC')
            ->getQuery()->enableResultCache(self::TTL, 'catalog_solutions')->getResult();
    }

    public function solution(?string $code): ?Solution
    {
        foreach ($this->enabledSolutions() as $solution) {
            if ($solution->getCode() === $code) {
                return $solution;
            }
        }

        return null;
    }

    public function solutionBySlug(string $slug, string $locale): ?Solution
    {
        foreach ($this->enabledSolutions() as $solution) {
            if ($solution->getSlug($locale) === $slug) {
                return $solution;
            }
        }
        foreach ($this->enabledSolutions() as $solution) {
            if (\in_array($slug, $solution->getSlugs(), true) || $solution->getSlug() === $slug) {
                return $solution;
            }
        }

        return null;
    }

    /**
     * @return list<Solution>
     */
    public function solutionsForSector(string $sectorCode): array
    {
        return array_values(array_filter($this->enabledSolutions(), static fn (Solution $s) => \in_array($sectorCode, $s->getSectors(), true)));
    }

    /**
     * Every feature code known by at least one enabled solution.
     *
     * @return list<string>
     */
    public function knownFeatureCodes(): array
    {
        $codes = [];
        foreach ($this->enabledSolutions() as $solution) {
            foreach ($solution->getEnabledFeatures() as $feature) {
                $codes[$feature->getCode()] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Global questions + the sector's own questions, enabled, ordered by position.
     *
     * @return list<Question>
     */
    public function questionsFor(?Sector $sector): array
    {
        $key = 'questions_'.($sector?->getCode() ?? 'none');

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $qb = $this->questions->createQueryBuilder('q')->andWhere('q.enabled = true');
        if (null !== $sector) {
            $qb->andWhere('q.sector IS NULL OR q.sector = :sector')->setParameter('sector', $sector);
        } else {
            $qb->andWhere('q.sector IS NULL');
        }

        return $this->memo[$key] = $qb->orderBy('q.position', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()->enableResultCache(self::TTL, 'catalog_'.$key)->getResult();
    }

    public function invalidate(): void
    {
        $this->cache->clear();
        $this->memo = [];
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
