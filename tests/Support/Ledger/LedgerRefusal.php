<?php

namespace Tests\Support\Ledger;

use RuntimeException;

/**
 * What the application answered when it refused an operation: the code of the refusal, and whether the code
 * says the books were asked for something malformed rather than something a business rule forbids.
 */
final class LedgerRefusal extends RuntimeException
{
    /**
     * @var list<string> The codes that mean a journal or an entity was malformed, not that a rule said no.
     */
    private const INTERNAL = [
        'too_few_lines', 'invalid_amount', 'line_on_both_sides', 'line_without_amount', 'unbalanced', 'invalid_source',
        'entity_account_missing', 'account_not_found', 'account_not_postable', 'party_account_mismatch', 'routing_account_unusable',
        'tax_accounts_exceed_tax', 'write_failed', 'foreign_unbalanced', 'invalid_party', 'routing_type_unsupported',
        'sale_not_postable', 'purchase_not_postable', 'routing_row_cancelled', 'payment_type_unknown', 'item_account_unusable',
    ];

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message === '' ? $reason : $reason . ': ' . $message);
    }

    public function isInternal(): bool
    {
        return in_array($this->reason, self::INTERNAL, true);
    }
}
