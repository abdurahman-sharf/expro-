<?php

namespace App\Libraries\Stock;

final readonly class UnitTemplate
{
    /**
     * @param list<TemplateUnit> $units The larger units, in the order they are shown.
     */
    public function __construct(
        public int $templateId,
        public string $name,
        public string $mainUnitName,
        public string $mainSmallName,
        public int $version,
        public ?int $previousTemplateId,
        public bool $active,
        public array $units,
        public ?string $mainUnitCode = null,
    ) {
    }

    public function unit(int $unitId): ?TemplateUnit
    {
        foreach ($this->units as $unit) {
            if ($unit->unitId === $unitId) {
                return $unit;
            }
        }

        return null;
    }
}
