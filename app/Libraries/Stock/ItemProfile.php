<?php

namespace App\Libraries\Stock;

final readonly class ItemProfile
{
    public function __construct(
        public int $itemId,
        public ?int $unitTemplateId,
        public TrackingType $tracking,
    ) {
    }
}
