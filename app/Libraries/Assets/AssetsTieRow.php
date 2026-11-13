<?php

namespace App\Libraries\Assets;

final readonly class AssetsTieRow
{
    /**
     * @param 'accumulated'|'cost' $kind
     * @param numeric-string       $register The assets of the register that are on the books, as an amount in the nature of the account.
     * @param numeric-string       $ledger   What the books hold in the account in the same way.
     */
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public string $kind,
        public string $register,
        public string $ledger,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function difference(): string
    {
        return bcsub($this->ledger, $this->register, 4);
    }

    public function ties(): bool
    {
        return bccomp($this->difference(), '0', 4) === 0;
    }
}
