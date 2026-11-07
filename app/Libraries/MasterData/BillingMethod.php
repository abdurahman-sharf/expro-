<?php

namespace App\Libraries\MasterData;

enum BillingMethod: string
{
    case Print = 'print';
    case Email = 'email';

    public function label(): string
    {
        return lang('Customers.billing_' . $this->value);
    }

    /**
     * @return array<string, string> The label of each method, by its code.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $method) {
            $options[$method->value] = $method->label();
        }

        return $options;
    }
}
