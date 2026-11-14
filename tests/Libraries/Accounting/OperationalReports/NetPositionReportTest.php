<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\NetPositionReport;
use App\Libraries\Accounting\OperationalReports\ReportDocuments;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The position of each invoice after its returns: the net is the total less the returns and not less the
 * payments, the state compares what was settled with the whole total, a credit note with no invoice is a
 * document of its own that can be left out, and every figure ties to the position of the customer in the
 * ledger.
 */
class NetPositionReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $second;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->second = $this->anotherCustomer();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private int $third;

    /**
     * An invoice of 115 paid 90, an invoice of 172.50 paid 156.25 with a refund invoice of 86.25 that the
     * books spend 16.25 of on what is left of it, and a credit note of 253 with no invoice.
     *
     * @return array{paid: int, returned: int, refund: int, orphan: int}
     */
    private function documents(): array
    {
        $this->third = $this->anotherCustomer('Third', 'Buyer');

        $paid     = $this->invoiceOf('115', $this->customerId, [$this->payment(lang('Sales.cash'), '90')]);
        $returned = $this->invoiceOf('172.50', $this->second, [$this->payment(lang('Sales.cash'), '156.25')]);
        $refund   = $this->refundInvoiceOf($returned, '86.25', $this->second);
        $orphan   = $this->save([$this->line($this->item('0', false), 0, '-1', '253')], [], [], SALE_TYPE_RETURN, $this->third, true, null, COMPLETED, NEW_ENTRY, null, returnOverrideReason: 'test');

        return ['paid' => $paid, 'returned' => $returned, 'refund' => $refund, 'orphan' => $orphan];
    }

    private function table(bool $details = false, bool $hideCredits = false, array $states = [], ?int $customerId = null): ReportTable
    {
        return (new NetPositionReport())->build(ReportScope::resolve(null, null), $details, '2020-01-01', date('Y-m-d'), $hideCredits, $states, $customerId);
    }

    /**
     * @return array<string, string>
     */
    private function rowOf(ReportTable $table, string $number): array
    {
        foreach ($table->blocks as $block) {
            foreach ($block->rows as $row) {
                if ($row['number'] === $number) {
                    return $row;
                }
            }
        }

        $this->fail("No row {$number}.");
    }

    private function numberOf(int $saleId): string
    {
        return 'POS ' . $saleId;
    }

    public function testBuild_TakesTheReturnsOffTheTotalAndNotThePayments(): void
    {
        $ids   = $this->documents();
        $table = $this->table();

        $paid     = $this->rowOf($table, $this->numberOf($ids['paid']));
        $returned = $this->rowOf($table, $this->numberOf($ids['returned']));

        $this->assertSame(['115.0000', '90.0000', '0.0000', '115.0000'], [$paid['total'], $paid['paid'], $paid['returns'], $paid['net']]);
        $this->assertSame(['172.5000', '172.5000', '-86.2500', '86.2500'], [$returned['total'], $returned['paid'], $returned['returns'], $returned['net']]);
    }

    public function testBuild_NamesTheStateByWhatWasSettledAgainstTheWholeTotal(): void
    {
        $ids   = $this->documents();
        $table = $this->table();

        $this->assertSame(lang('Accounting_operational_reports.state_partial'), $this->rowOf($table, $this->numberOf($ids['paid']))['status']);
        $this->assertSame(lang('Accounting_operational_reports.state_paid'), $this->rowOf($table, $this->numberOf($ids['returned']))['status']);
        $this->assertSame(lang('Accounting_operational_reports.state_credit'), $this->rowOf($table, $this->numberOf($ids['orphan']))['status']);
    }

    public function testBuild_ShowsACreditNoteWithNoInvoiceAsADocumentOfItsOwn(): void
    {
        $ids   = $this->documents();
        $table = $this->table();
        $row   = $this->rowOf($table, $this->numberOf($ids['orphan']));

        $this->assertSame(3, $table->documents);
        $this->assertSame(['-253.0000', '0.0000', '0.0000', '-253.0000'], [$row['total'], $row['paid'], $row['returns'], $row['net']]);
        $this->assertSame('34.5000', $table->total['total']);
        $this->assertSame('-51.7500', $table->total['net']);
    }

    public function testBuild_LeavesTheCreditNoteWithNoInvoiceOutOnRequest(): void
    {
        $this->documents();

        $shown  = $this->table();
        $hidden = $this->table(false, true);

        $this->assertSame(2, $hidden->documents);
        $this->assertSame(bcadd($shown->total['total'], '253', 4), $hidden->total['total']);
        $this->assertSame('201.2500', $hidden->total['net']);
    }

    public function testBuild_FiltersByStateAndByCustomer(): void
    {
        $this->documents();

        $partial = $this->table(false, false, ['partial']);
        $excess  = $this->table(false, false, ['excess']);
        $partly  = $this->table(false, false, ['partial_return']);

        $this->assertSame(['115.0000'], array_column($partial->blocks[0]->rows, 'total'));
        $this->assertSame([], $excess->blocks[0]->rows);
        $this->assertSame(['172.5000'], array_column($partly->blocks[0]->rows, 'total'));
        $this->assertSame(['172.5000'], array_column($this->table(false, false, ['paid'])->blocks[0]->rows, 'total'));
        $this->assertSame(1, $this->table(false, false, [], $this->customerId)->documents);
    }

    public function testBuild_NestsThePaymentsAndTheReturnsUnderTheirInvoice(): void
    {
        $ids   = $this->documents();
        $table = $this->table(true);

        $block = null;

        foreach ($table->blocks as $candidate) {
            if ($candidate->rows[0]['number'] === $this->numberOf($ids['returned'])) {
                $block = $candidate;
            }
        }

        $this->assertNotNull($block);
        $this->assertSame([lang('Accounting_operational_reports.kind_invoice'), lang('Accounting_operational_reports.row_payment'), lang('Accounting_operational_reports.row_credit_applied'), lang('Accounting_operational_reports.kind_refund_invoice')], array_column($block->rows, 'kind'));
    }

    /**
     * @return list<array<string, string>>
     */
    private function paymentRowsOf(ReportTable $table, int $saleId): array
    {
        foreach ($table->blocks as $block) {
            if ($block->rows[0]['number'] === $this->numberOf($saleId)) {
                return array_values(array_filter($block->rows, static fn (array $row): bool => $row['kind'] === lang('Accounting_operational_reports.row_payment')));
            }
        }

        $this->fail("No block for sale {$saleId}.");
    }

    public function testBuild_NestsOnlyThePaymentsThatSettledTheInvoiceAndNotTheCreditSoldOnDue(): void
    {
        $invoice = $this->invoiceOf('100', $this->customerId, [$this->payment(lang('Sales.cash'), '40'), $this->payment(lang('Sales.due'), '60')]);
        $table   = $this->table(true);

        $rows = $this->paymentRowsOf($table, $invoice);

        $this->assertSame(['40.0000'], array_column($rows, 'paid'));
        $this->assertSame('40.0000', $this->rowOf($table, $this->numberOf($invoice))['paid']);
    }

    public function testBuild_NestsNoPaymentWhoseJournalWasReversed(): void
    {
        $invoice = $this->invoiceOf('100', $this->customerId, [$this->payment(lang('Sales.cash'), '60')]);
        $journal = $this->accountingDb()->table('accounting_journals')->select('journal_id')->where(['source_type' => 'sale_payment', 'status' => 'posted'])->get()->getRow();

        $this->assertSame(['60.0000'], array_column($this->paymentRowsOf($this->table(true), $invoice), 'paid'));

        (new JournalService())->reverseAutomatic((int) $journal->journal_id, date('Y-m-d'), 'Taken back', null);

        $table = $this->table(true);

        $this->assertSame([], $this->paymentRowsOf($table, $invoice));
        $this->assertSame('0.0000', $this->rowOf($table, $this->numberOf($invoice))['paid']);
    }

    public function testBuild_TiesTheNestedPaymentsOfEveryInvoiceToItsSettledAmount(): void
    {
        $ids   = $this->documents();
        $table = $this->table(true);

        foreach (['paid', 'returned'] as $name) {
            $block = null;

            foreach ($table->blocks as $candidate) {
                if ($candidate->rows[0]['number'] === $this->numberOf($ids[$name])) {
                    $block = $candidate;
                }
            }

            $settled = array_sum(array_map('floatval', array_column(array_filter($block->rows, static fn (array $row): bool => in_array($row['kind'], [lang('Accounting_operational_reports.row_payment'), lang('Accounting_operational_reports.row_credit_applied')], true)), 'paid')));

            $this->assertEqualsWithDelta((float) $block->rows[0]['paid'], $settled, 0.00001);
        }
    }

    public function testBuild_RejectsBothChecksByNameWhenAnInvoiceIsInAForeignCurrency(): void
    {
        $ids = $this->documents();

        (new Currencies())->setActive('USD', true);
        $this->accountingDb()->table('sales')->whereIn('sale_id', array_values($ids))->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);

        $table = $this->table();

        $this->assertCount(2, $table->ties);

        foreach ($table->ties as $tie) {
            $this->assertTrue($tie->isRefused());
            $this->assertCount(1, $tie->refusalArguments);

            foreach ([$ids['paid'], $ids['returned'], $ids['orphan']] as $id) {
                $this->assertStringContainsString('POS ' . $id . ' (USD)', $tie->refusalArguments[0]);
            }
        }
    }

    public function testBuild_TiesTheNetAndTheSettledAmountsToTheLedgerOfEachCustomer(): void
    {
        $this->documents();

        $table = $this->table();

        $this->assertCount(2, $table->ties);
        $this->assertTrue($table->ties());
        $this->assertSame('201.2500', $table->ties[0]->ledger);
    }

    public function testBuild_ReadsNothingForAScopeOfNoBranch(): void
    {
        $this->documents();

        $table = (new NetPositionReport())->build(ReportScope::resolve(null, []), false, '2020-01-01', date('Y-m-d'));

        $this->assertTrue($table->isEmpty());
    }

    public function testBuild_NamesTheInvoiceInDollarsAndItsPaymentWhenTheChecksAreNotRun(): void
    {
        $this->enableDollars();
        $invoice = $this->foreignInvoice('100', [$this->payment(lang('Sales.cash'), '100')]);

        $this->assertGreaterThan(0, $invoice);

        $table = $this->table();

        $this->assertCount(2, $table->ties);

        foreach ($table->ties as $tie) {
            $this->assertTrue($tie->isRefused());
            $this->assertSame(['POS ' . $invoice . ' (USD)'], $tie->refusalArguments);
        }
    }

    public function testTies_ReadsTheLedgerOfEveryCustomerInAFewQueriesWhateverTheirNumber(): void
    {
        $this->documents();

        $others = [$this->anotherCustomer('Fourth', 'Buyer'), $this->anotherCustomer('Fifth', 'Buyer'), $this->anotherCustomer('Sixth', 'Buyer')];

        foreach ($others as $customer) {
            $this->invoiceOf('60', $customer, [$this->payment(lang('Sales.cash'), '10')]);
        }

        $scope     = ReportScope::resolve(null, null);
        $invoices  = (new ReportDocuments())->read($scope, '2020-01-01', date('Y-m-d'), [DocumentKind::Invoice]);
        $db        = $this->accountingDb();
        $questions = static fn (): int => (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->getRow()->Value;
        $before    = $questions();
        $ties      = (new NetPositionReport())->ties($invoices);
        $asked     = $questions() - $before - 1;

        $this->assertCount(5, array_unique(array_map(static fn ($invoice): int => (int) $invoice->customerId, $invoices)));
        $this->assertLessThanOrEqual(5, $asked);
        $this->assertTrue($ties[0]->ties());
        $this->assertTrue($ties[1]->ties());
        $this->assertSame('381.2500', $ties[0]->ledger);
    }
}
