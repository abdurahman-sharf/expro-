<?php

namespace App\Libraries\Assets;

/**
 * The sources of the journals of fixed assets. The reference system names them asset, asset_deprecation,
 * asset_sell and asset_write_off; the names here spell depreciation and sale out, and the journal of a
 * revaluation (asset_re_evaluate there) has no source because a revaluation is refused.
 */
final class AssetSources
{
    public const ACQUISITION  = 'asset';
    public const DEPRECIATION = 'asset_depreciation';
    public const SALE         = 'asset_sale';
    public const WRITE_OFF    = 'asset_write_off';
    public const ALL          = [
        self::ACQUISITION,
        self::DEPRECIATION,
        self::SALE,
        self::WRITE_OFF,
    ];
}
