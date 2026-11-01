<?php

namespace App\Libraries\Accounting;

final readonly class ItemRoutedAccount
{
    public function __construct(
        public int $accountId,
        public bool $own,
    ) {
    }
}
