<?php

namespace App\Controllers;

use App\Libraries\MasterData\LookupKind;

class Item_brands extends LookupScreen
{
    public function __construct()
    {
        parent::__construct('items', 'item_brands', LookupKind::ItemBrand);
    }
}
