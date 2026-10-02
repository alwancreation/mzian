<?php

declare(strict_types=1);

use App\Project\Entity\Project;
use App\Project\Workflow\ProjectWorkflowDefinition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// The project state machine is generated from ProjectWorkflowDefinition (one source of truth).
// Diagram: php bin/console workflow:dump project | dot -Tsvg -o project.svg
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'workflows' => [
            ProjectWorkflowDefinition::NAME => ProjectWorkflowDefinition::frameworkConfig(Project::class),
        ],
    ]);
};
