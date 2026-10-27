<?php

namespace App\Libraries;

enum InvoiceDiscountProblem: string
{
    case UnknownType    = 'unknown_type';
    case NotANumber     = 'not_a_number';
    case Negative       = 'negative';
    case PercentOver100 = 'percent_over_100';
    case ExceedsTotal   = 'exceeds_total';

    public function languageKey(): string
    {
        return match ($this) {
            self::UnknownType, self::NotANumber => 'Sales.invoice_discount_invalid',
            self::Negative       => 'Sales.negative_discount_invalid',
            self::PercentOver100 => 'Sales.discount_percent_exceeds_100',
            self::ExceedsTotal   => 'Sales.invoice_discount_exceeds_total',
        };
    }
}
