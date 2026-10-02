<?php

declare(strict_types=1);

namespace App\Api\Controller\V1;

use App\Api\Presenter\CommercePresenter;
use App\Project\Entity\Project;
use App\Project\Repository\ProjectRepository;
use App\Security\Entity\User;
use App\Security\Voter\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Project tracking for the customer who owns them.
 */
#[Route('/api/v1/projects')]
final class ProjectController extends AbstractController
{
    #[Route('', name: 'api_project_list', methods: ['GET'])]
    public function list(ProjectRepository $projects): JsonResponse
    {
        $user = $this->getUser();
        $customer = $user instanceof User ? $user->getCustomer() : null;
        if (null === $customer) {
            throw $this->createAccessDeniedException('A customer API token is required.');
        }

        return $this->json(['projects' => array_map(CommercePresenter::project(...), $projects->findBy(['customer' => $customer], ['id' => 'DESC']))]);
    }

    #[Route('/{reference}', name: 'api_project_show', requirements: ['reference' => 'MZ-[0-9]{4}-[A-Z0-9]{6}'], methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['reference' => 'reference'])] Project $project): JsonResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        return $this->json(CommercePresenter::project($project));
    }

    #[Route('/{reference}/status', name: 'api_project_status', requirements: ['reference' => 'MZ-[0-9]{4}-[A-Z0-9]{6}'], methods: ['GET'])]
    public function status(#[MapEntity(mapping: ['reference' => 'reference'])] Project $project): JsonResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        return $this->json(['reference' => $project->getReference()] + CommercePresenter::projectStatus($project));
    }
}
