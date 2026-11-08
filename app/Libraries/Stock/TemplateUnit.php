<?php

namespace App\Libraries\Stock;

final readonly class TemplateUnit
{
    public function __construct(
        public int $unitId,
        public string $name,
        public string $factor,
        public string $smallName,
        public int $position,
    ) {
    }
}
