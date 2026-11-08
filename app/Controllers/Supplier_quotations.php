<?php

namespace App\Controllers;

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;

class Supplier_quotations extends PurchaseCycleScreen
{
    public function __construct()
    {
        parent::__construct(PurchaseDocumentKind::SupplierQuotation);
    }
}
