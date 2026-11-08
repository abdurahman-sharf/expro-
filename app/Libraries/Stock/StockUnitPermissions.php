<?php

namespace App\Libraries\Stock;

/**
 * The permissions of the unit templates and of tracking. Nothing grants them automatically: choosing a
 * template for an item and changing how an item is tracked are decisions about what a quantity means.
 */
final class StockUnitPermissions
{
    public const MODULE            = 'stock_units';
    public const UNITS_MANAGE      = 'stk_units_manage';
    public const TRACKING_MANAGE   = 'stk_tracking_manage';
    public const BATCH_MANUAL_PICK = 'stk_batch_manual_pick';
    public const ALL               = [self::UNITS_MANAGE, self::TRACKING_MANAGE, self::BATCH_MANUAL_PICK];

    private function __construct()
    {
    }
}
