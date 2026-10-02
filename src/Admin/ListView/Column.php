<?php

declare(strict_types=1);

namespace App\Admin\ListView;

/**
 * Column of a generic admin list.
 * format: text | money | datetime | date | badge | bool | code | json.
 */
final readonly class Column
{
    public function __construct(
        public string $label,
        public string $path,
        public string $format = 'text',
        public ?string $currencyPath = null,
    ) {
    }
}
