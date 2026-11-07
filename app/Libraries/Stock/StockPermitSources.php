<?php

namespace App\Libraries\Stock;

/**
 * The sources of the journals the stock documents post: the add and issue permits and the stocktake
 * each have one, and a transfer between two stock locations has the journal of the sending side and,
 * when the locations are in two branches, the receipt of the receiving branch.
 */
final class StockPermitSources
{
    public const PERMIT           = 'stock_permit';
    public const TRANSFER         = 'stock_transfer_permit';
    public const TRANSFER_RECEIPT = 'stock_transfer_permit_receipt';
    public const STOCKTAKE        = 'stocktake';
    public const LEGS_DOCUMENT    = 'stock_permit';

    private function __construct()
    {
    }
}
