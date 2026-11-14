<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\BankAccountPlacement;
use App\Libraries\Accounting\LegacyDiscountFinding;
use App\Libraries\Accounting\LegacyDiscountVerdict;
use App\Libraries\Accounting\LegacyPurchaseDiscount;
use App\Libraries\Accounting\PaymentTreasuryStamping;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\SaleAmounts;
use CodeIgniter\Database\BaseConnection;

/**
 * The protections of data that existed before the accounting layer: the fixed purchase discounts
 * the migration cannot tell apart (E38), the bank treasuries without their bank details and the
 * ones not under the account of the banks (E40), and the payments that name no treasury although
 * their journal went into one and their way of payment is now routed to another account (E41): a
 * payment that names no treasury and is still where its routing points is how a payment is written
 * when no treasury is named. Each is read with the scan its own command uses, and nothing is changed
 * here.
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
        $scan      = new LegacyPurchaseDiscount($this->db);

        if ($appliedAt !== null) {
            $marked = $scan->marked();

            return CheckResult::pass(
                'The migration ' . self::PURCHASE_DISCOUNT_MIGRATION . " was applied on {$appliedAt}: a fixed discount saved since then is an amount for every unit, so there is nothing left to scan. {$marked['lines']} line(s) carry a fixed discount for the whole line, {$marked['discount']} in all.",
                [],
                ['migration_applied' => true, 'marked_lines' => $marked['lines'], 'marked_discount' => $marked['discount']],
            );
        }

        $plan     = $scan->plan();
        $findings = [...$plan['mark'], ...$plan['keep'], ...$plan['blocked']];
        $verdicts = array_count_values(array_map(static fn (LegacyDiscountFinding $finding): string => $finding->verdict->value, $findings));
        $window   = LegacyPurchaseDiscount::window($findings);

        $undetermined = array_values(array_filter($findings, static fn (LegacyDiscountFinding $finding): bool => $finding->verdict === LegacyDiscountVerdict::Undetermined));

        $data = [
            'migration_applied'   => false,
            'lines'               => count($findings),
            'verdicts'            => array_replace(array_fill_keys(array_map(static fn (LegacyDiscountVerdict $verdict): string => $verdict->value, LegacyDiscountVerdict::cases()), 0), $verdicts),
            'would_mark'          => count($plan['mark']),
            'would_mark_discount' => $this->discountOf($plan['mark']),
            'would_keep'          => count($plan['keep']),
            'blocked'             => count($plan['blocked']),
            'undetermined'        => count($undetermined),
            'at_stake'            => $this->atStake($undetermined),
            'window'              => $window,
            'resolution'          => $scan->resolution(),
        ];

        if ($plan['blocked'] !== []) {
            return CheckResult::fail(
                count($plan['blocked']) . ' purchase line(s) carry a fixed discount over several units that no evidence decides, so the migration ' . self::PURCHASE_DISCOUNT_MIGRATION . " stops without changing anything. The two readings of these discounts differ by {$data['at_stake']} in the purchase totals. Decide them with \"php spark accounting:purchase_discount resolve\" once the moment this database first ran the discount for every unit is known.",
                array_map(fn (LegacyDiscountFinding $finding): string => $this->describe($finding), $plan['blocked']),
                $data,
            );
        }

        if ($undetermined !== []) {
            return CheckResult::review(
                count($undetermined) . " undetermined purchase line(s) are settled by the decision recorded for this database: the owner confirms it before the migration runs. The two readings of these discounts differ by {$data['at_stake']} in the purchase totals.",
                array_map(fn (LegacyDiscountFinding $finding): string => $this->describe($finding), $undetermined),
                $data,
            );
        }

        return CheckResult::pass(
            $findings === []
                ? 'No purchase line carries a fixed discount over several units: the migration has nothing to mark.'
                : sprintf('%d purchase line(s) carry a fixed discount over several units and the books or the date decide every one: the migration marks %d and leaves %d. The lines it marks carry %s of discount, kept as an amount for the whole line.', count($findings), count($plan['mark']), count($plan['keep']), $data['would_mark_discount']),
            [],
            $data,
        );
    }

    private function describe(LegacyDiscountFinding $finding): string
    {
        return sprintf('purchase #%d line %d, item %d, %s units, discount %s, saved %s, the two readings differ by %s: %s', $finding->receivingId, $finding->line, $finding->itemId, $finding->units, $finding->discount, $finding->savedAt, $this->readingsDiffer($finding), $finding->reason);
    }

    /**
     * @param list<LegacyDiscountFinding> $findings
     *
     * @return numeric-string
     */
    private function discountOf(array $findings): string
    {
        $total = '0.00';

        foreach ($findings as $finding) {
            $total = bcadd($total, $finding->discount, 2);
        }

        return $total;
    }

    /**
     * @param list<LegacyDiscountFinding> $findings
     *
     * @return numeric-string
     */
    private function atStake(array $findings): string
    {
        $total = '0.00';

        foreach ($findings as $finding) {
            $total = bcadd($total, $this->readingsDiffer($finding), 2);
        }

        return $total;
    }

    /**
     * What a line's total changes by when its discount is read for every unit instead of for the
     * whole line.
     *
     * @return numeric-string
     */
    private function readingsDiffer(LegacyDiscountFinding $finding): string
    {
        return SaleAmounts::round(SaleAmounts::absolute(bcsub(bcmul($finding->units, $finding->discount, 8), $finding->discount, 8)), 2);
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
        $plan     = (new PaymentTreasuryStamping($this->db))->plan();
        $resolver = new RoutingResolver($this->db);
        $moved    = [];
        $inPlace  = [];

        foreach ($plan['stamp'] as $row) {
            $type   = PaymentType::fromLabel($row['payment_type']);
            $routed = $type === null ? null : $this->routedTo($resolver, $type);

            if ($routed !== null && $routed === $this->treasuryAccount($resolver, $row['treasury_id'])) {
                $inPlace[$row['table']] = ($inPlace[$row['table']] ?? 0) + 1;
            } else {
                $moved[] = $row;
            }
        }

        $data = ['stamp' => count($moved), 'in_place' => array_sum($inPlace), 'skipped' => count($plan['skipped'])];

        $skipped = array_map(static fn (array $row): string => "{$row['table']} #{$row['payment_id']} cannot be stamped: {$row['reason']}", $plan['skipped']);

        if ($moved !== []) {
            return CheckResult::fail(
                count($moved) . ' payment(s) name no treasury, their journal went into one, and their way of payment is now routed to another account: the next time their document is brought in line with the books they would move to that account. Stamping sets only the treasury of the payment: no journal, no amount and no balance changes.',
                [...array_map(static fn (array $row): string => "{$row['table']} #{$row['payment_id']} would take treasury {$row['treasury_id']}: its way of payment is now routed to another account than its journal", $moved), ...$skipped],
                $data,
                ['command' => 'accounting:stamp_payments', 'params' => ['apply' => null]],
            );
        }

        if ($skipped !== []) {
            return CheckResult::review(count($skipped) . ' payment(s) went into a treasury and cannot be stamped: the owner looks at each.', $skipped, $data);
        }

        if ($inPlace !== []) {
            ksort($inPlace);

            return CheckResult::pass(
                $data['in_place'] . ' payment(s) name no treasury and stay on the account their way of payment is routed to, which is where a payment goes when no treasury is named: nothing would move them, and the routing screen stamps them before it moves a way of payment.',
                array_map(static fn (string $table, int $count): string => "{$table}: {$count} payment(s) name no treasury and are where their way of payment is routed", array_keys($inPlace), $inPlace),
                $data,
            );
        }

        return CheckResult::pass('Every payment that went into a treasury names it, or has no journal in one.', [], $data);
    }

    private function routedTo(RoutingResolver $resolver, PaymentType $type): ?int
    {
        try {
            return $resolver->accountForPayment($type);
        } catch (PostingException) {
            return null;
        }
    }

    private function treasuryAccount(RoutingResolver $resolver, int $treasuryId): ?int
    {
        try {
            return $resolver->accountFor(RoutingKey::Treasuries, $treasuryId);
        } catch (PostingException) {
            return null;
        }
    }
}
