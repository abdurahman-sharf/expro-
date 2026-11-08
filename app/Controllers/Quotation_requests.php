<?php

namespace App\Controllers;

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;

class Quotation_requests extends PurchaseCycleScreen
{
    public function __construct()
    {
        parent::__construct(PurchaseDocumentKind::QuotationRequest);
    }
}
