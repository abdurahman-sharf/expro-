<?php

namespace App\Libraries\Assets;

final readonly class AssetsReportRow
{
    /**
     * @param numeric-string $cost
     * @param numeric-string $accumulated
     * @param numeric-string $bookValue
     * @param numeric-string $depreciation The depreciation of the months in the range of the depreciation dates.
     */
    public function __construct(
        public int $assetId,
        public string $code,
        public string $name,
        public string $branchCode,
        public ?string $category,
        public AssetStatus $status,
        public AssetMethod $method,
        public string $purchaseDate,
        public string $inServiceDate,
        public string $cost,
        public string $accumulated,
        public string $depreciation,
        public string $bookValue,
        public bool $onTheBooks,
        public ?string $disposedAt,
    ) {
    }
}
