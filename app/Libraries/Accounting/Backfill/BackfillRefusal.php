<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\PostingError;

enum BackfillRefusal: string
{
    case BeforeStartDate     = 'before_start_date';
    case BeforeOpening       = 'before_opening';
    case PeriodClosed        = 'period_closed';
    case OutsideFiscalYear   = 'outside_fiscal_year';
    case BranchUnknown       = 'branch_unknown';
    case BranchInactive      = 'branch_inactive';
    case PartyMissing        = 'party_missing';
    case TreasuryMissing     = 'treasury_missing';
    case ZeroTotal           = 'zero_total';
    case ReturnOriginMissing = 'return_origin_missing';
    case CrossBranchPayment  = 'cross_branch_payment';
    case ValuedByLedger      = 'valued_by_ledger';
    case PartlyPosted        = 'partly_posted';
    case DocumentChanged     = 'document_changed';
    case PostingRefused      = 'posting_refused';

    /**
     * What a refusal of the posting service is called here. The reasons that name a rule of the books
     * keep their own name, and any other refusal is the posting refused, with its code as the detail.
     */
    public static function ofPosting(PostingError $error): self
    {
        return match ($error) {
            PostingError::PeriodClosed      => self::PeriodClosed,
            PostingError::OutsideFiscalYear => self::OutsideFiscalYear,
            PostingError::BranchNotFound    => self::BranchUnknown,
            PostingError::BranchInactive    => self::BranchInactive,
            PostingError::ReturnOriginRequired, PostingError::ReturnOriginInvalid,
            PostingError::PurchaseReturnOriginRequired, PostingError::PurchaseReturnOriginInvalid => self::ReturnOriginMissing,
            PostingError::CrossBranchPaymentInvalid => self::CrossBranchPayment,
            default                                 => self::PostingRefused,
        };
    }
}
