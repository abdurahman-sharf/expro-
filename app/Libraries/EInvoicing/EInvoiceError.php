<?php

namespace App\Libraries\EInvoicing;

enum EInvoiceError: string
{
    case Disabled              = 'disabled';
    case AccountingDisabled    = 'accounting_disabled';
    case SettingInvalid        = 'setting_invalid';
    case IdentificationInvalid = 'identification_invalid';
    case AddressInvalid        = 'address_invalid';
    case ExemptionInvalid      = 'exemption_invalid';

    case SourceNotFound           = 'source_not_found';
    case SourceNotIssuable        = 'source_not_issuable';
    case SourceNotPosted          = 'source_not_posted';
    case ForeignCurrency          = 'foreign_currency';
    case HeaderChargesUnsupported = 'header_charges_unsupported';
    case LineTaxAmbiguous         = 'line_tax_ambiguous';
    case RateNotRepresentable     = 'rate_not_representable';
    case ExemptionNotConfigured   = 'exemption_not_configured';
    case NothingToIssue           = 'nothing_to_issue';

    case SellerNameMissing           = 'seller_name_missing';
    case SellerVatInvalid            = 'seller_vat_invalid';
    case SellerIdentificationMissing = 'seller_identification_missing';
    case SellerAddressIncomplete     = 'seller_address_incomplete';
    case BuyerNameMissing            = 'buyer_name_missing';
    case BuyerVatInvalid             = 'buyer_vat_invalid';
    case BuyerIdentificationMissing  = 'buyer_identification_missing';
    case BuyerAddressIncomplete      = 'buyer_address_incomplete';
    case StandardNeedsCustomer       = 'standard_needs_customer';

    case OriginalNotIssued = 'original_not_issued';
    case ReasonRequired    = 'reason_required';
    case ReasonTooLong     = 'reason_too_long';

    case TotalsDoNotTie = 'totals_do_not_tie';
    case RuleBroken     = 'rule_broken';

    case KeyPathUnsafe          = 'key_path_unsafe';
    case KeyUnreadable          = 'key_unreadable';
    case CurveUnsupported       = 'curve_unsupported';
    case CertificateUnreadable  = 'certificate_unreadable';
    case CertificateKeyMismatch = 'certificate_key_mismatch';
    case SigningFailed          = 'signing_failed';
    case QrTooLong              = 'qr_too_long';

    case DocumentNotFound = 'document_not_found';
    case ChainBroken      = 'chain_broken';
}
