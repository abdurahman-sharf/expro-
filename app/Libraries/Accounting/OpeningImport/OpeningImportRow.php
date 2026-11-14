<?php

namespace App\Libraries\Accounting\OpeningImport;

/**
 * A row of a file of opening balances as it was read and checked. The amount is without its sign, which
 * the side says: positive in the file is the side of the kind, negative is the other.
 */
final readonly class OpeningImportRow
{
    /**
     * @param list<OpeningImportError> $errors
     * @param numeric-string|null      $amount
     */
    public function __construct(
        public int $line,
        public string $party,
        public ?int $partyId,
        public string $partyName,
        public ?string $side,
        public ?string $amount,
        public ?string $date,
        public string $note,
        public array $errors,
        public string $detail = '',
        public bool $posted = false,
        public ?int $debitAccountId = null,
        public ?int $creditAccountId = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function isPending(): bool
    {
        return $this->errors === [] && ! $this->posted;
    }

    public function state(): string
    {
        return $this->errors !== [] ? 'error' : ($this->posted ? 'posted' : 'pending');
    }
}
