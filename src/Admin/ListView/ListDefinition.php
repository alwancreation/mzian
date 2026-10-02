<?php

declare(strict_types=1);

namespace App\Admin\ListView;

final readonly class ListDefinition
{
    /**
     * @param class-string      $entityClass
     * @param list<Column>      $columns
     * @param list<string>      $searchFields  DQL fields (alias "e") searched with LIKE
     * @param list<string>|null $statusChoices values of the "status" filter
     */
    public function __construct(
        public string $title,
        public string $entityClass,
        public array $columns,
        public array $searchFields = [],
        public ?array $statusChoices = null,
        public string $orderBy = 'e.id',
        public string $direction = 'DESC',
        public ?string $rowRoute = null,
        public string $rowRouteParam = 'id',
        public string $rowRouteProperty = 'id',
        public string $description = '',
    ) {
    }
}
