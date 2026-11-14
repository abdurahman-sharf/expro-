<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditOrderMove;
use App\Libraries\Accounting\CreditOrderPlan;
use App\Libraries\Accounting\CreditOrderPreview;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\SalesDebitNotes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\CreditSourceFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The read-only report of what the order of E57 does to the credit of the customers: which open items a
 * settlement run would move and which hold credit the order would put elsewhere. It is read and never
 * written, and what it says a run would do is what a run does.
 */
class CreditOrderPreviewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use CreditSourceFixtureTrait;
    use DatabaseFingerprintTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CreditOrderPreview $preview;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->preview = new CreditOrderPreview();
    }

    protected function tearDown(): void
    {
        $this->stopCreditSources();
        $this->setConfigValue('accounting_debit_note', '0');
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'sales_debit_note')->delete();
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @return array<int, array<string, string>> What stands from credit on each open item, by item and by source.
     */
    private function standingMap(?int $customerId = null): array
    {
        $map = [];

        foreach ((new CustomerLedger())->applications($customerId ?? $this->customerId) as $row) {
            $key    = CustomerLedger::targetOf($row);
            $source = $row->source_type . ':' . $row->source_id;

            $map[$key][$source] = bcadd($map[$key][$source] ?? '0', $row->amount, 4);
        }

        foreach ($map as &$sources) {
            ksort($sources);
        }

        unset($sources);
        ksort($map);

        return $map;
    }

    /**
     * What the real settlement leaves from nothing, which is where the order puts the credit of the customer,
     * or null when it refuses. The applications that stood are put back as they were, ids included.
     *
     * @return array<int, array<string, string>>|null
     */
    private function standingMapFromNothing(int $customerId): ?array
    {
        $db   = $this->accountingDb();
        $rows = $db->table('customer_credit_applications')->where('customer_id', $customerId)->get()->getResultArray();

        $this->dropApplications($customerId);

        try {
            $this->settle($customerId);

            $map = $this->standingMap($customerId);
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::CreditRefunded, $refusal->error);

            $map = null;
        }

        $this->dropApplications($customerId);

        if ($rows !== []) {
            $db->table('customer_credit_applications')->insertBatch($rows);
        }

        return $map;
    }

    /**
     * Reads the plan of the customer, checks that reading it changed no row, checks that the items it says do not
     * hold their credit where the order puts it are those where a settlement from nothing puts it elsewhere, runs
     * the settlement for real and checks that the items the plan says would move are the items that moved, from
     * what to what.
     */
    private function assertPreviewIsWhatARunDoes(?int $customerId = null): CreditOrderPlan
    {
        $customerId ??= $this->customerId;
        $before       = $this->standingMap($customerId);
        $print        = $this->databaseFingerprint();
        $plan         = $this->preview->forCustomer($customerId);

        $this->assertDatabaseUnchanged($print, 'Reading the plan changed a row');

        $fromNothing = $this->standingMapFromNothing($customerId);

        if ($fromNothing !== null) {
            $this->assertSame($this->movesBetween($before, $fromNothing), $this->movesOf($plan->differences), 'The items the plan says hold credit the order would put elsewhere are not the items a settlement from nothing puts it on');
        }

        try {
            $this->settle($customerId);
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::CreditRefunded, $refusal->error);
            $this->assertSame(AccountingError::CreditRefunded, $plan->blocked, 'The run was refused and the plan did not say so');
            $this->assertSame([], $plan->moves);

            return $plan;
        }

        $this->assertNull($plan->blocked, 'The plan said the run would be refused and it was not');

        $this->assertSame($this->movesBetween($before, $this->standingMap($customerId)), $this->movesOf($plan->moves), 'The items the plan says a run would move are not the items it moved');

        return $plan;
    }

    /**
     * @param array<int, array<string, string>> $before
     * @param array<int, array<string, string>> $after
     *
     * @return array<int, array{array<string, string>, array<string, string>}> The items whose credit differs, by item.
     */
    private function movesBetween(array $before, array $after): array
    {
        $moves = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (($before[$key] ?? []) !== ($after[$key] ?? [])) {
                $moves[$key] = [$before[$key] ?? [], $after[$key] ?? []];
            }
        }

        ksort($moves);

        return $moves;
    }

    /**
     * @param list<CreditOrderMove> $moves
     *
     * @return array<int, array{array<string, string>, array<string, string>}>
     */
    private function movesOf(array $moves): array
    {
        $said = [];

        foreach ($moves as $move) {
            $said[$move->key] = [$move->before, $move->after];
        }

        ksort($said);

        return $said;
    }

    private function dropApplications(?int $customerId = null): void
    {
        $this->accountingDb()->table('customer_credit_applications')->where('customer_id', $customerId ?? $this->customerId)->delete();
    }

    private function debitNote(string $amount, int $daysAgo): int
    {
        $this->setConfigValue('accounting_debit_note', '1');

        return (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo($daysAgo), 'amount' => $amount, 'tax_percent' => '0', 'note' => 'freight'], $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]));
    }

    private function ownedOpening(string $amount, int $daysAgo): int
    {
        $this->setConfigValue('accounting_customer_opening', '1');

        return (new CustomerOpening())->create($this->customerId, CustomerOpening::OWES_US, $amount, $this->daysAgo($daysAgo), '', $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE]));
    }

    public function testForCustomer_SaysNothingForACustomerWhoseCreditIsSettledAndStandsWhereTheOrderPutsIt(): void
    {
        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '100.00');
        $this->invoiceDated('60.00', $this->daysAgo(5));
        $this->invoiceDated('80.00', $this->daysAgo(2));

        $plan = $this->assertPreviewIsWhatARunDoes();

        $this->assertTrue($plan->isEmpty());
        $this->assertSame([], $plan->moves);
        $this->assertSame([], $plan->differences);
        $this->assertNull($plan->blocked);
    }

    public function testForCustomer_ListsTheItemsAnUnsettledCustomersCreditWouldGoToInTheOrderOfDateThenCreation(): void
    {
        $advance = $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '100.00');
        $later   = $this->invoiceDated('80.00', $this->daysAgo(2));
        $earlier = $this->invoiceDated('60.00', $this->daysAgo(5));

        $this->assertGreaterThan($later, $earlier, 'The invoice dated earlier was created later');

        $this->dropApplications();

        $plan   = $this->assertPreviewIsWhatARunDoes();
        $source = CreditSource::CUSTOMER_ADVANCE . ':' . $advance;

        $this->assertCount(2, $plan->moves);
        $this->assertSame([CreditOrderMove::INVOICE, $earlier, $this->daysAgo(5), [], [$source => '60.0000']], [$plan->moves[0]->kind, $plan->moves[0]->documentId, $plan->moves[0]->date, $plan->moves[0]->before, $plan->moves[0]->after], 'The older day is served first although the invoice was created later');
        $this->assertSame([CreditOrderMove::INVOICE, $later, [$source => '40.0000']], [$plan->moves[1]->kind, $plan->moves[1]->documentId, $plan->moves[1]->after]);
        $this->assertSame(['0.0000', '60.0000'], [$plan->moves[0]->beforeTotal(), $plan->moves[0]->afterTotal()]);
        $this->assertSame([$earlier, $later], array_map(static fn (CreditOrderMove $move): int => $move->key, $plan->differences), 'With nothing standing, the order puts the credit where the run puts it');
        $this->assertSame($this->standingMap()[$earlier], $plan->moves[0]->after);
    }

    public function testForCustomer_BreaksATieOfDatesByTheOrderTheInvoicesWereCreatedIn(): void
    {
        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '70.00');

        $first  = $this->invoiceDated('50.00', $this->daysAgo(3));
        $second = $this->invoiceDated('50.00', $this->daysAgo(3));

        $this->dropApplications();

        $plan = $this->assertPreviewIsWhatARunDoes();

        $this->assertSame([$first, $second], array_map(static fn (CreditOrderMove $move): int => $move->documentId, $plan->moves));
        $this->assertSame(['50.0000', '20.0000'], array_map(static fn (CreditOrderMove $move): string => $move->afterTotal(), $plan->moves));
    }

    public function testForCustomer_ListsTheItemsThatHoldCreditTheOrderWouldPutElsewhereAndSaysARunDoesNotMoveIt(): void
    {
        $older = $this->invoiceDated('100.00', $this->daysAgo(10));
        $newer = $this->invoiceDated('100.00', $this->daysAgo(5));
        $note  = $this->creditNoteOf($newer, '60.00');

        $this->accountingDb()->table('customer_credit_applications')->where('customer_id', $this->customerId)->update(['invoice_sale_id' => $newer]);

        $this->assertSame([CreditSource::CREDIT_NOTE . ':' . $note => '60.0000'], $this->standingMap()[$newer], 'The credit note of the newer invoice stands on it, as the order that put the invoice a credit note came from first would have it');

        $plan   = $this->assertPreviewIsWhatARunDoes();
        $source = CreditSource::CREDIT_NOTE . ':' . $note;

        $this->assertSame([], $plan->moves, 'A run leaves what stands');
        $this->assertSame([$older, $newer], array_map(static fn (CreditOrderMove $move): int => $move->documentId, $plan->differences));
        $this->assertSame([[], [$source => '60.0000']], [$plan->differences[0]->before, $plan->differences[0]->after], 'The order puts the credit note on the older invoice');
        $this->assertSame([[$source => '60.0000'], []], [$plan->differences[1]->before, $plan->differences[1]->after]);
        $this->assertSame([$newer => [$source => '60.0000']], $this->standingMap(), 'The run left it where it was');
        $this->assertFalse($plan->isEmpty());
    }

    public function testForCustomer_ListsTheItemsAnOverAppliedCreditWouldBeCutFromAndTheOneItWouldBeGivenTo(): void
    {
        $older = $this->invoiceDated('100.00', $this->daysAgo(10));
        $newer = $this->invoiceDated('100.00', $this->daysAgo(5), null, null, [$this->payment(lang('Sales.cash'), '70.00')]);
        $note  = $this->creditNoteOf($newer, '60.00');

        $this->accountingDb()->table('customer_credit_applications')->where('customer_id', $this->customerId)->update(['invoice_sale_id' => $newer]);

        $plan   = $this->assertPreviewIsWhatARunDoes();
        $source = CreditSource::CREDIT_NOTE . ':' . $note;

        $this->assertSame([$older, $newer], array_map(static fn (CreditOrderMove $move): int => $move->documentId, $plan->moves));
        $this->assertSame([[], [$source => '30.0000']], [$plan->moves[0]->before, $plan->moves[0]->after], 'What was cut off the newer invoice goes to the older');
        $this->assertSame([[$source => '60.0000'], [$source => '30.0000']], [$plan->moves[1]->before, $plan->moves[1]->after], 'The newer invoice owed 30 after its payment and held 60');
    }

    public function testForCustomer_ListsTheItemThatHoldsCreditFromASourceThatIsGone(): void
    {
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(5));

        $this->accountingDb()->table('customer_credit_applications')->insert(['customer_id' => $this->customerId, 'invoice_sale_id' => $invoice, 'source_type' => CreditSource::CUSTOMER_ADVANCE, 'source_id' => 99999, 'amount' => '20.0000']);

        $plan = $this->assertPreviewIsWhatARunDoes();

        $this->assertCount(1, $plan->moves);
        $this->assertSame([[CreditSource::CUSTOMER_ADVANCE . ':99999' => '20.0000'], []], [$plan->moves[0]->before, $plan->moves[0]->after]);
        $this->assertSame([], $this->standingMap());
    }

    public function testForCustomer_CutsACreditThatGaveMoreThanItCameToFromTheNewestApplicationFirst(): void
    {
        $advance = $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '100.00');
        $first   = $this->invoiceDated('60.00', $this->daysAgo(5));
        $second  = $this->invoiceDated('60.00', $this->daysAgo(2));
        $source  = CreditSource::CUSTOMER_ADVANCE . ':' . $advance;

        $this->assertSame([$first => [$source => '60.0000'], $second => [$source => '40.0000']], $this->standingMap());

        $this->accountingDb()->table('customer_credit_applications')->where(['customer_id' => $this->customerId, 'invoice_sale_id' => $first])->update(['amount' => '90.0000']);

        $plan = $this->assertPreviewIsWhatARunDoes();

        $this->assertNotSame([], $plan->moves);
        $this->assertSame([$first => [$source => '60.0000'], $second => [$source => '40.0000']], $this->standingMap(), 'The run cut the credit back to what it came to');
    }

    public function testForCustomer_CountsAnOpeningBalanceAndASalesDebitNoteAsItemsOfTheOrder(): void
    {
        $opening = $this->ownedOpening('300.00', 60);
        $note    = $this->debitNote('100.00', 30);
        $invoice = $this->invoiceDated('50.00', $this->daysAgo(2));
        $advance = $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '420.00');
        $source  = CreditSource::CUSTOMER_ADVANCE . ':' . $advance;

        $this->dropApplications();

        $plan = $this->assertPreviewIsWhatARunDoes();

        $this->assertSame([CreditOrderMove::OPENING, CreditOrderMove::DEBIT_NOTE, CreditOrderMove::INVOICE], array_map(static fn (CreditOrderMove $move): string => $move->kind, $plan->moves));
        $this->assertSame([$opening, $note, $invoice], array_map(static fn (CreditOrderMove $move): int => $move->documentId, $plan->moves));
        $this->assertSame([[$source => '300.0000'], [$source => '100.0000'], [$source => '20.0000']], array_map(static fn (CreditOrderMove $move): array => $move->after, $plan->moves));
        $this->assertSame([SalesDebitNotes::keyOf($note)], array_values(array_map(static fn (CreditOrderMove $move): int => $move->key, array_filter($plan->moves, static fn (CreditOrderMove $move): bool => $move->kind === CreditOrderMove::DEBIT_NOTE))));
    }

    public function testForCustomer_AppliesOnlyWhatIsLeftOfACreditAfterARefundTookPartOfIt(): void
    {
        $advance = $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '100.00');

        $this->refundCustomerCredit('40.00');

        $invoice = $this->invoiceDated('100.00', $this->daysAgo(1));

        $this->dropApplications();

        $plan = $this->assertPreviewIsWhatARunDoes();

        $this->assertSame([$invoice], array_map(static fn (CreditOrderMove $move): int => $move->documentId, $plan->moves));
        $this->assertSame([CreditSource::CUSTOMER_ADVANCE . ':' . $advance => '60.0000'], $plan->moves[0]->after, 'Of 100 the refund took 40');
    }

    public function testForCustomer_SaysARunWouldBeRefusedAndMovesNothingWhenACreditThatWasPaidBackWouldShrink(): void
    {
        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '100.00');
        $this->invoiceDated('50.00', $this->daysAgo(1));
        $this->refundCustomerCredit('50.00');
        $this->accountingDb()->table('customer_credit_refund_sources')->update(['amount' => '150.0000']);

        $print = $this->databaseFingerprint();
        $plan  = $this->preview->forCustomer($this->customerId);

        $this->assertDatabaseUnchanged($print);
        $this->assertSame(AccountingError::CreditRefunded, $plan->blocked);
        $this->assertSame([], $plan->moves);
        $this->assertFalse($plan->isEmpty());

        try {
            $this->settle();

            $this->fail('The run was not refused.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::CreditRefunded, $refusal->error);
        }
    }

    public function testAll_ListsOnlyTheCustomersWithSomethingToSayAndChangesNothing(): void
    {
        $other = $this->anotherCustomer();

        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '100.00');
        $this->invoiceDated('60.00', $this->daysAgo(5));
        $this->invoiceDated('40.00', $this->daysAgo(1), null, $other);

        $older = $this->invoiceDated('100.00', $this->daysAgo(10), null, $other);
        $newer = $this->invoiceDated('100.00', $this->daysAgo(5), null, $other);

        $this->creditNoteOf($newer, '60.00', $other);
        $this->accountingDb()->table('customer_credit_applications')->where('customer_id', $other)->update(['invoice_sale_id' => $newer]);

        $print = $this->databaseFingerprint();
        $plans = $this->preview->all();

        $this->assertDatabaseUnchanged($print, 'Reading every customer changed a row');
        $this->assertSame([$other], array_map(static fn (CreditOrderPlan $plan): int => $plan->customerId, $plans));
        $this->assertSame('Hind Saad', $plans[0]->customerName);
        $this->assertSame([$older, $newer], array_map(static fn (CreditOrderMove $move): int => $move->documentId, $plans[0]->differences));
        $this->assertContains($this->customerId, $this->preview->customerIds());
        $this->assertContains($other, $this->preview->customerIds());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function seeds(): array
    {
        $seeds = [];

        for ($seed = 1; $seed <= 24; $seed++) {
            $seeds['seed ' . $seed] = [$seed];
        }

        return $seeds;
    }

    #[DataProvider('seeds')]
    public function testForCustomer_ForesesWhatARunDoesInAScenarioOfCreditInvoicesRefundsAndDamagedApplications(int $seed): void
    {
        mt_srand($seed);

        $invoices = [];

        for ($count = mt_rand(2, 4); $count > 0; $count--) {
            $amount     = mt_rand(20, 200);
            $payments   = mt_rand(0, 2) === 0 ? [$this->payment(lang('Sales.cash'), (string) mt_rand(1, $amount - 1) . '.00')] : [];
            $invoices[] = $this->invoiceDated($amount . '.00', $this->daysAgo(mt_rand(1, 30)), null, null, $payments);
        }

        for ($count = mt_rand(1, 3); $count > 0; $count--) {
            match (mt_rand(0, 2)) {
                0       => $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, mt_rand(30, 250) . '.00'),
                1       => $this->creditNoteOf($invoices[array_rand($invoices)], mt_rand(10, 20) . '.00'),
                default => $this->standingFor($this->customerId) ?? $this->giveCustomerCredit(CreditSource::OPENING, mt_rand(30, 150) . '.00'),
            };
        }

        $free = bcadd($this->position()->credit(), '0', 2);

        if (bccomp($free, '1', 2) > 0 && mt_rand(0, 1) === 1) {
            $this->refundCustomerCredit(bcdiv($free, (string) mt_rand(2, 4), 2));
        }

        $rows = $this->accountingDb()->table('customer_credit_applications')->where('customer_id', $this->customerId)->get()->getResult();

        match (mt_rand(0, 4)) {
            0       => $this->dropApplications(),
            1       => array_map(fn (object $row) => mt_rand(0, 1) === 1 ? $this->accountingDb()->table('customer_credit_applications')->where('application_id', $row->application_id)->delete() : null, $rows),
            2       => array_map(fn (object $row) => $row->invoice_sale_id === null ? null : $this->accountingDb()->table('customer_credit_applications')->where('application_id', $row->application_id)->update(['invoice_sale_id' => $invoices[array_rand($invoices)]]), $rows),
            3       => array_map(fn (object $row) => $this->accountingDb()->table('customer_credit_applications')->where('application_id', $row->application_id)->update(['amount' => bcmul($row->amount, '1.5', 4)]), $rows),
            default => null,
        };

        $this->assertPreviewIsWhatARunDoes();
    }

    private function standingFor(int $customerId): ?int
    {
        $opening = (new CustomerOpening())->standingFor($customerId);

        return $opening === null ? null : (int) $opening->opening_id;
    }
}
