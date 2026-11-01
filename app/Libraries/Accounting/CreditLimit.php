<?php

namespace App\Libraries\Accounting;

use App\Libraries\Pricing\SalesControlSettings;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The credit limit and the credit days of a customer, and the check that a sale on credit is made
 * within them. The check reads the books after the sale was posted and before it is committed, so
 * it sees what the customer owes on invoices that are still open with the new sale in it: more than
 * the limit refuses the sale, and so does an oldest open invoice older than the credit days. It runs
 * inside the transaction of the sale and under the lock of the customer, so two sales made at the same
 * moment cannot both pass on what only one of them fits in. Credit given by an advance payment that
 * is not yet spent on an invoice is not counted, and drafts, quotes and returns never reach it. An
 * employee who holds the permission to override sells over the limit, and the audit log says so.
 */
final class CreditLimit
{
    public const AUDIT_OVERRIDE = 'credit_limit_override';
    public const AUDIT_REFUSED  = 'credit_limit_refused';
    public const DAYS_MAX       = 65535;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->guard = $guard ?? new AccountingGuard();
        $this->audit = new AuditLog($this->db);
    }

    /**
     * @return array{limit: string, days: int} A limit of 0 and 0 days mean the customer has none.
     */
    public function termsOf(int $customerId): array
    {
        $row = $this->db->table('customers')->select('credit_limit, credit_period_days')->where('person_id', $customerId)->get()->getRow();

        return $row === null ? ['limit' => '0.0000', 'days' => 0] : ['limit' => bcadd((string) $row->credit_limit, '0', 4), 'days' => (int) $row->credit_period_days];
    }

    /**
     * Whether what was typed is a limit and days that can be saved, so a form can refuse them before
     * it saves anything else.
     *
     * @throws AccountingException
     */
    public function checkTerms(?string $limit, ?string $days): void
    {
        $limit = trim((string) $limit) === '' ? '0' : trim((string) $limit);
        $days  = trim((string) $days) === '' ? '0' : trim((string) $days);

        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $limit) !== 1 || ! ctype_digit($days) || (int) $days > self::DAYS_MAX) {
            throw new AccountingException(AccountingError::CreditTermsInvalid, 'The credit limit or the credit days are not valid.');
        }
    }

    /**
     * @param string|null $limit What is left out (null) stays as it is, for a form that shows only one of the two.
     *
     * @throws AccountingException
     */
    public function saveTerms(int $customerId, ?string $limit, ?string $days, ?int $employeeId): void
    {
        $this->checkTerms($limit, $days);

        $before = $this->termsOf($customerId);
        $limit  = $limit === null ? $before['limit'] : (trim($limit) === '' ? '0' : trim($limit));
        $days   = $days === null ? (string) $before['days'] : (trim($days) === '' ? '0' : trim($days));
        $limit  = bcadd($limit, '0', 4);

        if (bccomp($before['limit'], $limit, 4) === 0 && $before['days'] === (int) $days) {
            return;
        }

        $this->db->transStart();
        $this->db->table('customers')->where('person_id', $customerId)->update(['credit_limit' => $limit, 'credit_period_days' => (int) $days]);
        $this->audit->write('customer', $customerId, 'customer_credit_changed', $employeeId, JournalOrigin::User, ['before' => $before, 'after' => ['limit' => $limit, 'days' => (int) $days]]);
        $this->db->transComplete();
    }

    /**
     * Why the sale that was just posted may not stand, or null when the customer is within the
     * limit and the days: the sale put nothing on credit, the customer has no limit or no days, or
     * the controls are off.
     */
    public function refusalFor(int $customerId, int $saleId, string $today): ?AccountingException
    {
        [$terms, $checksLimit, $checksPeriod] = $this->checksOf($customerId);

        if (! $checksLimit && ! $checksPeriod) {
            return null;
        }

        $position = (new CustomerLedger($this->db))->position($customerId);
        $sale     = $position->invoice($saleId);

        if ($sale === null || bccomp($sale->open(), '0', SaleAmounts::SCALE) <= 0) {
            return null;
        }

        $open = $position->open();

        if ($checksLimit && bccomp($open, $terms['limit'], SaleAmounts::SCALE) > 0) {
            return new AccountingException(
                AccountingError::CreditLimitExceeded,
                'With this sale the customer would owe more than the credit limit.',
                ['customer_id' => $customerId, 'name' => $this->nameOf($customerId), 'limit' => bcadd($terms['limit'], '0', 2), 'balance' => bcadd($open, '0', 2), 'new_debt' => $sale->open()],
            );
        }

        if (! $checksPeriod) {
            return null;
        }

        $oldest = null;

        foreach ($position->invoices as $invoice) {
            if ($invoice->saleId !== $saleId && bccomp($invoice->open(), '0', SaleAmounts::SCALE) > 0 && ($oldest === null || $invoice->date < $oldest)) {
                $oldest = $invoice->date;
            }
        }

        $age = $oldest === null ? 0 : AgingBuckets::ageInDays($oldest, $today);

        if ($age > $terms['days']) {
            return new AccountingException(
                AccountingError::CreditPeriodExceeded,
                'The oldest open invoice of the customer is older than the credit days.',
                ['customer_id' => $customerId, 'name' => $this->nameOf($customerId), 'count' => $terms['days'], 'days' => $age, 'oldest_date' => $oldest],
            );
        }

        return null;
    }

    /**
     * Whether a sale of the customer is held to a limit or to days, which is when what the sale
     * leaves open is worth reading before an edit.
     */
    public function watches(int $customerId): bool
    {
        [, $checksLimit, $checksPeriod] = $this->checksOf($customerId);

        return $checksLimit || $checksPeriod;
    }

    /**
     * What an invoice has open in the books, 0 for a sale that is not an issued invoice.
     */
    public function openOf(int $customerId, int $saleId): string
    {
        $invoice = (new CustomerLedger($this->db))->position($customerId)->invoice($saleId);

        return $invoice === null ? '0' : $invoice->open();
    }

    /**
     * Why an edit of the payments of an issued invoice may not stand, or null: the edit left no
     * more open on the invoice than it had, or the customer is still within the limit and the
     * days. Taking a payment away or lowering it is a sale on credit made after the sale, and the
     * limit holds for it as it holds at issue.
     */
    public function refusalForEdit(int $customerId, int $saleId, string $openBefore, string $today): ?AccountingException
    {
        if (bccomp($this->openOf($customerId, $saleId), $openBefore, SaleAmounts::SCALE) <= 0) {
            return null;
        }

        return $this->refusalFor($customerId, $saleId, $today);
    }

    public function mayOverride(?int $employeeId): bool
    {
        return $this->guard->has(AccountingGuard::CREDIT_OVERRIDE, $employeeId);
    }

    /**
     * Writes down that a sale went over the limit with the permission to, in the transaction that
     * saves the sale.
     */
    public function recordOverride(int $saleId, ?int $branchId, ?int $employeeId, AccountingException $refusal): void
    {
        $this->audit->write('sale', $saleId, self::AUDIT_OVERRIDE, $employeeId, JournalOrigin::User, $this->detailsOf($refusal), $branchId);
    }

    /**
     * Writes down that a sale was refused. It runs after the transaction has rolled back, or the
     * entry would go with it.
     */
    public function recordRefusal(int $customerId, ?int $branchId, ?int $employeeId, AccountingException $refusal): void
    {
        $this->audit->write('customer', $customerId, self::AUDIT_REFUSED, $employeeId, JournalOrigin::User, $this->detailsOf($refusal), $branchId);
    }

    /**
     * @return array{0: array{limit: string, days: int}, 1: bool, 2: bool} The terms of the customer, and whether the limit and the days are checked for the customer.
     */
    private function checksOf(int $customerId): array
    {
        $settings = new SalesControlSettings($this->db);
        $terms    = $this->termsOf($customerId);

        return [
            $terms,
            $settings->creditLimitEnabled() && bccomp($terms['limit'], '0', 4) > 0,
            $settings->creditPeriodEnabled() && $terms['days'] > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsOf(AccountingException $refusal): array
    {
        return ['rule' => $refusal->error->value, ...array_diff_key($refusal->context, ['name' => true])];
    }

    private function nameOf(int $customerId): string
    {
        $row = $this->db->table('people')->select("TRIM(CONCAT(first_name, ' ', last_name)) AS name", false)->where('person_id', $customerId)->get()->getRow();

        return $row === null ? '' : (string) $row->name;
    }
}
