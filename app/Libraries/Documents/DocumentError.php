<?php

namespace App\Libraries\Documents;

enum DocumentError: string
{
    case AmountMalformed      = 'amount_malformed';
    case AmountNegative       = 'amount_negative';
    case AmountTooLarge       = 'amount_too_large';
    case CurrencyUnsupported  = 'currency_unsupported';
    case CustomFieldNotFound  = 'custom_field_not_found';
    case CustomFieldName      = 'custom_field_name';
    case CustomFieldNameTaken = 'custom_field_name_taken';
    case CustomFieldOptions   = 'custom_field_options';
    case CustomFieldType      = 'custom_field_type';
    case CustomFieldValue     = 'custom_field_value';
    case CustomFieldRequired  = 'custom_field_required';
    case CustomFieldEntity    = 'custom_field_entity';
    case PermissionDenied     = 'permission_denied';
    case QrAmountMalformed    = 'qr_amount_malformed';
    case QrSellerMissing      = 'qr_seller_missing';
    case QrTimestampMalformed = 'qr_timestamp_malformed';
    case QrValueTooLong       = 'qr_value_too_long';
    case QrVatNumberMissing   = 'qr_vat_number_missing';
}
