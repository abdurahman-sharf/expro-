<?php

namespace App\Libraries\MasterData;

enum LookupKind: string
{
    case CustomerClassification = 'customer_classification';
    case ItemBrand              = 'item_brand';

    public function table(): string
    {
        return match ($this) {
            self::CustomerClassification => 'customer_classifications',
            self::ItemBrand              => 'item_brands',
        };
    }

    public function idColumn(): string
    {
        return match ($this) {
            self::CustomerClassification => 'classification_id',
            self::ItemBrand              => 'brand_id',
        };
    }

    /**
     * The table and the column that refer to a row of the list.
     *
     * @return array{string, string}
     */
    public function reference(): array
    {
        return match ($this) {
            self::CustomerClassification => ['customers', 'classification_id'],
            self::ItemBrand              => ['items', 'brand_id'],
        };
    }
}
