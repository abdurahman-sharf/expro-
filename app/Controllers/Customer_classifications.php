<?php

namespace App\Controllers;

use App\Libraries\MasterData\LookupKind;

class Customer_classifications extends LookupScreen
{
    public function __construct()
    {
        parent::__construct('customers', 'customer_classifications', LookupKind::CustomerClassification);
    }
}
