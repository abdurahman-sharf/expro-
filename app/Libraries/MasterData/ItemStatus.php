<?php

namespace App\Libraries\MasterData;

/**
 * The status of a product. Inactive and suspended are two labels for one state: the product is left out of
 * the lists of new documents and nothing else changes. The values are the ones Daftra stores.
 */
enum ItemStatus: int
{
    case Active    = 0;
    case Inactive  = 1;
    case Suspended = 2;

    public function isHidden(): bool
    {
        return $this !== self::Active;
    }

    public function label(): string
    {
        return lang('Items.status_' . strtolower($this->name));
    }

    /**
     * @return array<int, string> The label of each status, by its value.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
