<?php

namespace App\Libraries\Assets;

/**
 * The sources of the journals of fixed assets. A revaluation has no source because it is refused.
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
