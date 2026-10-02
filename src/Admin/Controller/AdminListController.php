<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\ListView\AdminListRegistry;
use App\Shared\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Generic, paginated, searchable read-only lists for admin sections.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', defaults: ['_locale' => 'en'])]
final class AdminListController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly AdminListRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route('/list/{section}', name: 'admin_list', requirements: ['section' => '[a-z-]+'], methods: ['GET'])]
    public function list(string $section, Request $request): Response
    {
        $definition = $this->registry->get($section) ?? throw $this->createNotFoundException();

        $page = max(1, $request->query->getInt('page', 1));
        $search = trim($request->query->getString('q'));
        $status = $request->query->getString('status');

        $qb = $this->em->createQueryBuilder()->select('e')->from($definition->entityClass, 'e');
        if ('' !== $search && [] !== $definition->searchFields) {
            $or = $qb->expr()->orX();
            foreach ($definition->searchFields as $field) {
                $or->add($qb->expr()->like($field, ':search'));
            }
            $qb->andWhere($or)->setParameter('search', '%'.addcslashes($search, '%_').'%');
        }
        if ('' !== $status && null !== $definition->statusChoices && \in_array($status, $definition->statusChoices, true)) {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }
        $qb->orderBy($definition->orderBy, $definition->direction)
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE);

        $paginator = new Paginator($qb, fetchJoinCollection: false);
        $total = \count($paginator);

        return $this->render('admin/list.html.twig', [
            'section' => $section,
            'definition' => $definition,
            'rows' => iterator_to_array($paginator),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'search' => $search,
            'status' => $status,
            'row_route' => null !== $definition->rowRoute && null !== $this->router->getRouteCollection()->get($definition->rowRoute) ? $definition->rowRoute : null,
        ]);
    }

    #[Route('/logs/{id}', name: 'admin_log', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function log(AuditLog $log): Response
    {
        return $this->render('admin/log.html.twig', ['log' => $log]);
    }
}
