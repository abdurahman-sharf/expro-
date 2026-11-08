<?php

namespace App\Libraries\MasterData;

enum CustomerType: string
{
    case Individual = 'individual';
    case Commercial = 'commercial';

    public function label(): string
    {
        return lang('Customers.type_' . $this->value);
    }

    /**
     * @return array<string, string> The label of each type, by its code.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
