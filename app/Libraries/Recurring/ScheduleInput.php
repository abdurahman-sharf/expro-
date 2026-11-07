<?php

namespace App\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\InvoiceTiming;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\SalesOrders\LineInput;
use CodeIgniter\Database\BaseConnection;

/**
 * What a person types to make or change a schedule, checked and put in the shape it is stored in. Nothing
 * is written here: a refusal names the first thing that is wrong, in the words of its error.
 */
final class ScheduleInput
{
    public const MAX_REMINDERS = 10;
    public const COMMENT_MAX   = 500;
    public const ISSUE_DRAFT   = 'draft';
    public const ISSUE_NOW     = 'issue';
    private const MONEY        = '/^\d{1,13}(\.\d{1,2})?$/';

    /**
     * @param array<string, mixed> $input
     *
     * @return array{columns: array<string, mixed>, terms: RecurrenceTerms, active: bool, lines: list<array<string, mixed>>, reminders: list<array{send_when: int, days: int|null}>}
     *
     * @throws AccountingException|BranchException
     */
    public static function normalise(RecurringKind $kind, array $input, BaseConnection $db, BranchGuard $branches, ?int $employeeId, string $today, bool $isNew): array
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';

        if ($name === '' || mb_strlen($name) > RecurrenceTerms::MAX_NAME_LENGTH) {
            throw new AccountingException(AccountingError::RecurringNameInvalid, 'The name of the schedule is required.', ['max' => RecurrenceTerms::MAX_NAME_LENGTH]);
        }

        $terms = RecurrenceTerms::fromInput($input, $kind);

        if ($isNew && $terms->startDate < $today) {
            throw new AccountingException(AccountingError::RecurringStartInPast, 'The first date of a new schedule is not a day that has passed.', ['date' => $terms->startDate]);
        }

        $comment = is_string($input['comment'] ?? null) ? trim($input['comment']) : '';

        if (mb_strlen($comment) > self::COMMENT_MAX) {
            throw new AccountingException(AccountingError::DescriptionTooLong, 'The comment is too long.', ['max' => self::COMMENT_MAX]);
        }

        $columns = [
            'name'              => $name,
            'period_count'      => $terms->periodCount,
            'period_unit'       => $terms->unit->value,
            'start_date'        => $terms->startDate,
            'end_date'          => $terms->endDate,
            'max_repeats'       => $terms->maxRepeats,
            'issue_before_days' => $terms->issueBeforeDays,
            'comment'           => $comment,
        ];

        $lines     = [];
        $reminders = [];

        if ($kind === RecurringKind::Invoice) {
            [$template, $lines] = self::invoice($input, $db, $branches, $employeeId);
            $reminders          = self::reminders($input['reminders'] ?? []);
        } else {
            $template = self::expense($input, $db, $branches, $employeeId);
        }

        return [
            'columns'   => [...$columns, ...$template],
            'terms'     => $terms,
            'active'    => ! array_key_exists('active', $input) || in_array($input['active'], [true, 1, '1', 'on'], true),
            'lines'     => $lines,
            'reminders' => $reminders,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     *
     * @throws AccountingException|BranchException
     */
    private static function invoice(array $input, BaseConnection $db, BranchGuard $branches, ?int $employeeId): array
    {
        $customerId = (int) ($input['customer_id'] ?? 0);

        if ($customerId <= 0 || $db->table('customers')->where(['person_id' => $customerId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::RecurringCustomerInvalid, 'The customer does not exist.', ['name' => (string) $customerId]);
        }

        $locationId = (int) ($input['location_id'] ?? 0);
        $location   = $locationId > 0 ? $db->table('stock_locations')->select('location_id, branch_id')->where(['location_id' => $locationId, 'deleted' => 0])->get()->getRow() : null;

        if ($location === null) {
            throw new AccountingException(AccountingError::RecurringLocationInvalid, 'The stock location does not exist.', ['name' => (string) $locationId]);
        }

        $branches->requireBranch((int) $location->branch_id, $employeeId);

        $lines = LineInput::normalise($input['lines'] ?? null, (int) $location->location_id, $db, AccountingError::RecurringNoLines, AccountingError::RecurringLineInvalid);
        $terms = trim((string) ($input['payment_terms_days'] ?? '')) === '' ? null : InvoiceTiming::terms($input['payment_terms_days']);
        $mode  = ($input['issue_mode'] ?? self::ISSUE_DRAFT) === self::ISSUE_NOW ? self::ISSUE_NOW : self::ISSUE_DRAFT;

        return [[
            'branch_id'          => (int) $location->branch_id,
            'customer_id'        => $customerId,
            'payment_terms_days' => $terms,
            'issue_mode'         => $mode,
        ], $lines];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws AccountingException|BranchException
     */
    private static function expense(array $input, BaseConnection $db, BranchGuard $branches, ?int $employeeId): array
    {
        $invalid    = static fn (string $why): AccountingException => new AccountingException(AccountingError::RecurringExpenseInvalid, $why);
        $categoryId = (int) ($input['expense_category_id'] ?? 0);

        if ($categoryId <= 0 || $db->table('expense_categories')->where(['expense_category_id' => $categoryId, 'deleted' => 0])->countAllResults() === 0) {
            throw $invalid('The category of the expense does not exist.');
        }

        $amount = self::money($input['amount'] ?? '');
        $tax    = self::money(trim((string) ($input['tax_amount'] ?? '')) === '' ? '0' : $input['tax_amount']);

        if ($amount === null || $tax === null || bccomp($amount, '0', 2) <= 0 || bccomp($tax, $amount, 2) > 0) {
            throw $invalid('The amount is above zero and the tax is not more than the amount.');
        }

        $payment = is_string($input['payment_type'] ?? null) ? $input['payment_type'] : '';

        if (! array_key_exists($payment, get_payment_options())) {
            throw $invalid('The way of payment is not one of the list.');
        }

        $supplierId = trim((string) ($input['supplier_id'] ?? '')) === '' ? null : (int) $input['supplier_id'];

        if ($supplierId !== null && $db->table('suppliers')->where(['person_id' => $supplierId, 'deleted' => 0])->countAllResults() === 0) {
            throw $invalid('The supplier does not exist.');
        }

        $treasuryId = (int) ($input['treasury_id'] ?? 0) > 0 ? (int) $input['treasury_id'] : null;
        $treasury   = $treasuryId === null ? null : $db->table('treasuries')->select('treasury_id, branch_id, active')->where('treasury_id', $treasuryId)->get()->getRow();

        if ($treasuryId !== null && ($treasury === null || (int) $treasury->active !== 1)) {
            throw $invalid('The treasury does not exist or is not active.');
        }

        $taxCode  = is_string($input['supplier_tax_code'] ?? null) ? trim($input['supplier_tax_code']) : '';
        $branchId = $treasury === null ? $branches->defaultBranchId($employeeId) : (int) $treasury->branch_id;

        if (mb_strlen($taxCode) > 255) {
            throw $invalid('The tax code of the supplier is too long.');
        }

        $branches->requireBranch($branchId, $employeeId);

        return [
            'branch_id'           => $branchId,
            'expense_category_id' => $categoryId,
            'amount'              => $amount,
            'tax_amount'          => $tax,
            'payment_type'        => $payment,
            'supplier_id'         => $supplierId,
            'supplier_tax_code'   => $taxCode === '' ? null : $taxCode,
            'treasury_id'         => $treasuryId,
        ];
    }

    /**
     * @return list<array{send_when: int, days: int|null}>
     *
     * @throws AccountingException
     */
    private static function reminders(mixed $raw): array
    {
        $invalid = static fn (): AccountingException => new AccountingException(AccountingError::RecurringRemindersInvalid, 'The reminders are not valid.', ['max' => self::MAX_REMINDERS]);

        if (! is_array($raw)) {
            throw $invalid();
        }

        $rows = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                throw $invalid();
            }

            $when = trim((string) ($row['send_when'] ?? ''));

            if ($when === '') {
                continue;
            }

            if (preg_match('/^[0-4]$/', $when) !== 1) {
                throw $invalid();
            }

            $needsDays = (int) $when >= 3;
            $days      = trim((string) ($row['days'] ?? ''));

            if ($needsDays && preg_match('/^\d{1,4}$/', $days) !== 1) {
                throw $invalid();
            }

            $rows[] = ['send_when' => (int) $when, 'days' => $needsDays ? (int) $days : null];
        }

        if (count($rows) > self::MAX_REMINDERS) {
            throw $invalid();
        }

        return $rows;
    }

    private static function money(mixed $value): ?string
    {
        $text = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        return preg_match(self::MONEY, $text) === 1 ? bcadd($text, '0', 2) : null;
    }
}
