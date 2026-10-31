<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The reconciliation statement of a bank account on a date, read and never written. On the side of the
 * books: the balance of the account up to the date, which is what the treasury holds and what the
 * ledger shows for the account, plus the statement lines that are not yet in the books, which is the
 * balance the books would have with what the bank has already done. On the side of the bank: the
 * balance on the statement, as it was entered, plus the lines of the books that the bank has not yet
 * done (the deposits in transit, and the payments that have not been presented, which are minus). The
 * difference of the two is nothing when every line of the bank is accounted for. The lines of the books
 * dated before the first statement line are carried, as no statement could show them, and are left out
 * of the bank side, because the bank balance already holds them: the difference then says that the
 * balance the bank started from is not the balance the books carried to it. Which lines are open on a
 * date is told by BankBook.
 */
final class ReconciliationReport
{
    public const LIST_LIMIT = 500;
    private const SCALE     = SaleAmounts::SCALE;

    private BaseConnection $db;
    private BankAccess $access;
    private BankBook $book;
    private ReconciliationLock $lock;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->access = new BankAccess($this->db, $guard, $branches);
        $this->book   = new BankBook($this->db);
        $this->lock   = new ReconciliationLock($this->db);
    }

    /**
     * @throws AccountingException
     */
    public function build(int $treasuryId, string $asOf, ?string $statementBalance, ?int $employeeId = null): ReconciliationFigures
    {
        $treasury  = $this->access->bankAccount($treasuryId, $employeeId);
        $accountId = $this->access->treasuries()->accountOf($treasuryId) ?? 0;

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);

        if ($date === false || $date->format('Y-m-d') !== $asOf) {
            throw new AccountingException(AccountingError::InvalidDate, "{$asOf} is not a date.", ['date' => $asOf]);
        }

        $statementBalance = $statementBalance === null || trim($statementBalance) === '' ? null : self::amount($statementBalance);
        $start            = $this->book->coverageStart($treasuryId);
        $book             = $this->book->balance($accountId, $asOf);
        $unmatched        = $this->book->openStatementTotals($treasuryId, $asOf);
        $outstanding      = $this->book->openTotals($treasuryId, $accountId, $asOf, $start);
        $carried          = $this->book->openTotals($treasuryId, $accountId, $asOf, $start, 'carried');
        $adjustedBook     = bcadd($book, $unmatched['total'], self::SCALE);
        $adjustedBank     = $statementBalance === null ? null : bcadd($statementBalance, $outstanding['total'], self::SCALE);

        return new ReconciliationFigures(
            $treasury,
            $accountId,
            $asOf,
            $start,
            $this->lock->through($treasuryId),
            $book,
            $statementBalance,
            $this->book->statementTotal($treasuryId, $asOf),
            $unmatched,
            $outstanding,
            $carried,
            $this->book->openStatementLines($treasuryId, $asOf, self::LIST_LIMIT),
            $accountId === 0 ? [] : $this->book->openLines($treasuryId, $accountId, $asOf, $start, 'outstanding', self::LIST_LIMIT),
            $accountId === 0 ? [] : $this->book->openLines($treasuryId, $accountId, $asOf, $start, 'carried', self::LIST_LIMIT),
            $adjustedBook,
            $adjustedBank,
            $adjustedBank === null ? null : bcsub($adjustedBank, $adjustedBook, self::SCALE),
            count($this->book->matchesMadeBy($treasuryId, $asOf)),
        );
    }

    /**
     * A balance as typed: digits with a point or a comma for the decimals, up to four, and a minus
     * sign, since a bank account can be overdrawn.
     *
     * @throws AccountingException
     */
    public static function amount(string $text): string
    {
        $clean = str_replace([' ', "\u{00A0}"], '', trim($text));

        if (preg_match('/^-?\d{1,11}([.,]\d{1,4})?$/', $clean) !== 1) {
            throw new AccountingException(AccountingError::BankReconciliationBalanceInvalid, 'The balance is not a number.', ['name' => $text]);
        }

        return bcadd(str_replace(',', '.', $clean), '0', self::SCALE);
    }
}
