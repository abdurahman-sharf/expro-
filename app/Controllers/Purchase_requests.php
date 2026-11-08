<?php

namespace App\Controllers;

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;

class Purchase_requests extends PurchaseCycleScreen
{
    public function __construct()
    {
        parent::__construct(PurchaseDocumentKind::Request);
    }
}
