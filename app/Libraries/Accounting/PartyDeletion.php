<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Whether a customer or a supplier may be deleted: not while the books say they hold anything. A party
 * whose position is not zero (an invoice or a purchase still open, credit not yet spent, an opening
 * balance, or any balance on the account) would otherwise vanish from the collection and payment screens
 * and leave its balance on an account nobody can reach. The check and the delete run under the lock of
 * the party, so a payment that arrives while the party is deleted is either counted or waits. While
 * accounting is off nothing is held in the books and nothing is refused.
 */
final class PartyDeletion
{
    public const CUSTOMER = EntityAccountService::CUSTOMER;
    public const SUPPLIER = EntityAccountService::SUPPLIER;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * What a party still holds in the books, which is the largest of its open documents, its unspent
     * credit and the balance of its account, or null when the party holds nothing or accounting is off.
     *
     * @param self::CUSTOMER|self::SUPPLIER $party
     *
     * @return numeric-string|null
     */
    public function unsettled(string $party, int $partyId): ?string
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return null;
        }

        $position = $party === self::CUSTOMER ? (new CustomerLedger($this->db))->position($partyId) : (new SupplierLedger($this->db))->position($partyId);
        $amount   = '0';

        foreach ([$position->accountBalance, $position->open(), $position->credit()] as $figure) {
            $figure = ltrim($figure, '-');

            if (bccomp($figure, $amount, SaleAmounts::SCALE) > 0) {
                $amount = $figure;
            }
        }

        return bccomp($amount, '0', SaleAmounts::SCALE) === 0 ? null : bcadd($amount, '0', SaleAmounts::SCALE);
    }

    /**
     * Runs the deletion of a party that holds nothing, and says what it holds when it holds something and
     * the deletion is not run.
     *
     * @param self::CUSTOMER|self::SUPPLIER $party
     * @param callable(): bool              $delete
     *
     * @return array{deleted: bool, amount: numeric-string|null}
     *
     * @throws AccountingException When another change to the party does not finish in time.
     */
    public function deleteIfSettled(string $party, int $partyId, callable $delete): array
    {
        $work = function () use ($party, $partyId, $delete): array {
            $amount = $this->unsettled($party, $partyId);

            return $amount === null ? ['deleted' => $delete(), 'amount' => null] : ['deleted' => false, 'amount' => $amount];
        };

        return $party === self::CUSTOMER ? (new CustomerLock($this->db))->run($partyId, $work) : (new SupplierLock($this->db))->run($partyId, $work);
    }
}
