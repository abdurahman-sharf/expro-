<?php

namespace App\Libraries\Accounting;

enum ReadinessCode: string
{
    case NoAccounts                 = 'no_accounts';
    case BaseCurrencyMissing        = 'base_currency_missing';
    case StartDateMissing           = 'start_date_missing';
    case NoFiscalYear               = 'no_fiscal_year';
    case StartDateOutsideFiscalYear = 'start_date_outside_fiscal_year';
    case RoutingUnmapped            = 'routing_unmapped';
    case RoutingAccountInvalid      = 'routing_account_invalid';
    case PaymentTypeUnrouted        = 'payment_type_unrouted';
    case PaymentTypeUnknown         = 'payment_type_unknown';
    case StockLocationWithoutBranch = 'stock_location_without_branch';
    case TaxOutputSharedAccount     = 'tax_output_shared_account';
    case SalesBeforeStartDate       = 'sales_before_start_date';
}
