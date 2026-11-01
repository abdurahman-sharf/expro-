<?php

namespace App\Libraries\Stock;

/**
 * The permissions of the stock documents. The module opens the screens, and each document has the
 * permission that changes it. Nothing grants them automatically, and the approval of a stocktake and
 * of a stock request is a permission of its own.
 */
final class StockPermissions
{
    public const MODULE           = 'stock_documents';
    public const PERMITS_MANAGE   = 'stk_permits_manage';
    public const STOCKTAKE_MANAGE = 'stk_stocktake_manage';
    public const STOCKTAKE_APPROVE = 'stk_stocktake_approve';
    public const REQUESTS_MANAGE  = 'stk_requests_manage';
    public const REQUESTS_APPROVE = 'stk_requests_approve';

    public const ALL = [
        self::PERMITS_MANAGE,
        self::STOCKTAKE_MANAGE,
        self::STOCKTAKE_APPROVE,
        self::REQUESTS_MANAGE,
        self::REQUESTS_APPROVE,
    ];

    private function __construct()
    {
    }
}
