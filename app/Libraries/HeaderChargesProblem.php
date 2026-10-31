<?php

namespace App\Libraries;

enum HeaderChargesProblem: string
{
    case NotANumber        = 'not_a_number';
    case NegativeShipping  = 'negative_shipping';
    case TaxPercentInvalid = 'tax_percent_invalid';
    case UnknownOption     = 'unknown_option';
    case LabelTooLong      = 'label_too_long';
    case ExceedsTotal      = 'exceeds_total';

    public function languageKey(): string
    {
        return match ($this) {
            self::NotANumber, self::TaxPercentInvalid, self::UnknownOption => 'Sales.header_charges_invalid',
            self::NegativeShipping => 'Sales.shipping_negative',
            self::LabelTooLong     => 'Sales.adjustment_label_too_long',
            self::ExceedsTotal     => 'Sales.adjustment_exceeds_total',
        };
    }
}
