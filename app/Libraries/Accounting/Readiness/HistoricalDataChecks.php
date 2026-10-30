<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\BankAccountPlacement;
use App\Libraries\Accounting\LegacyDiscountFinding;
use App\Libraries\Accounting\LegacyDiscountVerdict;
use App\Libraries\Accounting\LegacyPurchaseDiscount;
use App\Libraries\Accounting\PaymentTreasuryStamping;
use CodeIgniter\Database\BaseConnection;

/**
 * The protections of data that existed before the accounting layer: the fixed purchase discounts
 * the migration cannot tell apart (E38), the bank treasuries without their bank details and the
 * ones not under the account of the banks (E40), and the payments that name no treasury although
 * their journal went into one (E41). Each is read with the scan its own command uses, and nothing
 * is changed here.
 */
final class HistoricalDataChecks extends CheckGroup
{
    public const PURCHASE_DISCOUNT_MIGRATION = 'LegacyFixedPurchaseDiscount';

    public function __construct(BaseConnection $db, private MigrationChecks $migrations)
    {
        parent::__construct($db);
    }

    public function run(): array
    {
        $bank = ['treasuries', 'accounting_accounts', 'accounting_routing'];

        return [
            $this->check('purchase_discount', 'E38-SCAN', 'Legacy fixed purchase discounts', ['receivings', 'receivings_items.discount_type', 'receivings_items.receiving_quantity'], fn (): CheckResult => $this->purchaseDiscount()),
            $this->check('bank_placement', 'E40-BANK-DETAILS', 'Bank accounts under the main account of the banks', $bank, fn (): CheckResult => $this->bankPlacement()),
            $this->check('bank_details', 'E40-BANK-DETAILS', 'Bank name and account number of bank treasuries', $bank, fn (): CheckResult => $this->bankDetails()),
            $this->check('payment_stamping', 'E41-STAMP', 'Payments not stamped with their treasury', ['treasuries', 'accounting_journals', 'accounting_journal_lines', 'accounting_accounts.entity_type', 'sales_payments.treasury_id', 'sales_payments.cash_adjustment', 'receivings_payments.treasury_id'], fn (): CheckResult => $this->paymentStamping()),
        ];
    }

    private function purchaseDiscount(): CheckResult
    {
        $appliedAt = $this->migrations->appliedAt(self::PURCHASE_DISCOUNT_MIGRATION);

        if ($appliedAt !== null) {
            return CheckResult::pass(
                'The migration ' . self::PURCHASE_DISCOUNT_MIGRATION . " was applied on {$appliedAt}: a fixed discount saved since then is an amount for every unit, so there is nothing left to scan.",
                [],
                ['migration_applied' => true],
            );
        }

        $scan     = new LegacyPurchaseDiscount($this->db);
        $plan     = $scan->plan();
        $findings = [...$plan['mark'], ...$plan['keep'], ...$plan['blocked']];
        $verdicts = array_count_values(array_map(static fn (LegacyDiscountFinding $finding): string => $finding->verdict->value, $findings));
        $window   = LegacyPurchaseDiscount::window($findings);

        $undetermined = array_values(array_filter($findings, static fn (LegacyDiscountFinding $finding): bool => $finding->verdict === LegacyDiscountVerdict::Undetermined));

        $data = [
            'migration_applied' => false,
            'lines'             => count($findings),
            'verdicts'          => array_replace(array_fill_keys(array_map(static fn (LegacyDiscountVerdict $verdict): string => $verdict->value, LegacyDiscountVerdict::cases()), 0), $verdicts),
            'would_mark'        => count($plan['mark']),
            'would_keep'        => count($plan['keep']),
            'blocked'           => count($plan['blocked']),
            'undetermined'      => count($undetermined),
            'window'            => $window,
            'resolution'        => $scan->resolution(),
        ];

        if ($plan['blocked'] !== []) {
            return CheckResult::fail(
                count($plan['blocked']) . ' purchase line(s) carry a fixed discount over several units that no evidence decides, so the migration ' . self::PURCHASE_DISCOUNT_MIGRATION . ' stops without changing anything. Decide them with "php spark accounting:purchase_discount resolve" once the moment this database first ran the discount for every unit is known.',
                array_map(fn (LegacyDiscountFinding $finding): string => $this->describe($finding), $plan['blocked']),
                $data,
            );
        }

        if ($undetermined !== []) {
            return CheckResult::review(
                count($undetermined) . ' undetermined purchase line(s) are settled by the decision recorded for this database: the owner confirms it before the migration runs.',
                array_map(fn (LegacyDiscountFinding $finding): string => $this->describe($finding), $undetermined),
                $data,
            );
        }

        return CheckResult::pass(
            $findings === []
                ? 'No purchase line carries a fixed discount over several units: the migration has nothing to mark.'
                : sprintf('%d purchase line(s) carry a fixed discount over several units and the books or the date decide every one: the migration marks %d and leaves %d.', count($findings), count($plan['mark']), count($plan['keep'])),
            [],
            $data,
        );
    }

    private function describe(LegacyDiscountFinding $finding): string
    {
        return sprintf('purchase #%d line %d, item %d, %s units, discount %s, saved %s: %s', $finding->receivingId, $finding->line, $finding->itemId, $finding->units, $finding->discount, $finding->savedAt, $finding->reason);
    }

    private function bankPlacement(): CheckResult
    {
        $early = $this->beforeBankMigration();

        if ($early !== null) {
            return $early;
        }

        $pending = (new BankAccountPlacement($this->db))->pending();

        if ($pending === []) {
            return CheckResult::pass('Every bank account is under the main account of the banks.', [], ['pending' => 0]);
        }

        return CheckResult::fail(
            count($pending) . ' bank account(s) are not under the main account of the banks (1202). Moving them changes only their place in the chart: no journal line and no balance.',
            array_map(static fn (object $row): string => "treasury {$row->treasury_id} ({$row->name}), account {$row->code}", $pending),
            ['pending' => count($pending)],
            ['command' => 'accounting:bank_accounts', 'params' => ['move' => null]],
        );
    }

    private function bankDetails(): CheckResult
    {
        $early = $this->beforeBankMigration();

        if ($early !== null) {
            return $early;
        }

        $incomplete = (new BankAccountPlacement($this->db))->incomplete();

        if ($incomplete === []) {
            return CheckResult::pass('Every bank account has the name of its bank and its account number.', [], ['incomplete' => 0]);
        }

        return CheckResult::fail(
            count($incomplete) . ' bank account(s) lack the name of the bank or the account number: complete them from the treasuries screen.',
            array_map(static fn (object $row): string => "treasury {$row->treasury_id} ({$row->name}) lacks " . implode(' and ', array_filter([
                trim((string) $row->bank_name) === '' ? 'the bank name' : null,
                trim((string) $row->account_number) === '' ? 'the account number' : null,
            ])), $incomplete),
            ['incomplete' => count($incomplete)],
        );
    }

    private function beforeBankMigration(): ?CheckResult
    {
        if ($this->schema->hasColumn('treasuries', 'bank_name') && $this->schema->hasColumn('treasuries', 'account_number')) {
            return null;
        }

        $banks = $this->db->table('treasuries')->where('kind', 'bank')->countAllResults();

        return CheckResult::skipped(
            "Not checked: the bank details columns do not exist yet (the BankAccounts migration is pending). {$banks} bank treasur" . ($banks === 1 ? 'y exists' : 'ies exist') . ' and each lacks them until somebody completes them.',
            ['bank_treasuries' => $banks],
        );
    }

    private function paymentStamping(): CheckResult
    {
        $plan = (new PaymentTreasuryStamping($this->db))->plan();

        $data = ['stamp' => count($plan['stamp']), 'skipped' => count($plan['skipped'])];

        $skipped = array_map(static fn (array $row): string => "{$row['table']} #{$row['payment_id']} cannot be stamped: {$row['reason']}", $plan['skipped']);

        if ($plan['stamp'] !== []) {
            return CheckResult::fail(
                count($plan['stamp']) . ' payment(s) name no treasury although their journal went into one. Stamping sets only the treasury of the payment: no journal, no amount and no balance changes.',
                [...array_map(static fn (array $row): string => "{$row['table']} #{$row['payment_id']} would take treasury {$row['treasury_id']}", $plan['stamp']), ...$skipped],
                $data,
                ['command' => 'accounting:stamp_payments', 'params' => ['apply' => null]],
            );
        }

        if ($skipped !== []) {
            return CheckResult::review(count($skipped) . ' payment(s) went into a treasury and cannot be stamped: the owner looks at each.', $skipped, $data);
        }

        return CheckResult::pass('Every payment that went into a treasury names it, or has no journal in one.', [], $data);
    }
}
