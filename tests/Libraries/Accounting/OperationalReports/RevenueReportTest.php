<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\OperationalReports\RevenueGroup;
use App\Libraries\Accounting\OperationalReports\RevenueReport;
use App\Libraries\Accounting\OperationalReports\StatusFilter;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The revenue of the books split into paid, unpaid and returned: the one rule for the three, the
 * identity of the net sales across every grouping, the periods with nothing in them, the filters and
 * the check against the journals.
 */
class RevenueReportTest extends CIUnitTestCase
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

    /**
     * Two invoices of 115 and 230, a credit note of 57.50 on the first and a refund invoice of 28.75 on the
     * second, whose net is 258.75. The first is paid 100 in cash. The books settle the open part of each
     * invoice with the credit of its own return: 15 of the first and 28.75 of the second.
     *
     * @return array{first: int, second: int, note: int, refund: int}
     */
    private function sales(): array
    {
        $day = $this->today();

        $first  = $this->invoiceDated('115', $day, null, $this->customerId, [$this->payment(lang('Sales.cash'), '100')]);
        $second = $this->invoiceDated('230', $day, null, $this->second);

        return ['first' => $first, 'second' => $second, 'note' => $this->creditNoteOf($first, '57.50', $this->customerId), 'refund' => $this->refundInvoiceOf($second, '28.75', $this->second)];
    }

    private function today(): string
    {
        return date('Y-m-d');
    }

    private function table(RevenueGroup $group, bool $details = false, ?string $from = '2020-01-01', ?string $to = null, array $statuses = [], ?int $customerId = null, ?int $employeeId = null, bool $notTaxed = false): ReportTable
    {
        return (new RevenueReport())->build(ReportScope::resolve(null, null), $group, $details, $from, $to ?? $this->today(), $this->today(), $statuses, $customerId, $employeeId, $notTaxed);
    }

    /**
     * @return array<string, string>|null
     */
    private function rowOf(ReportTable $table, string $number): ?array
    {
        foreach ($table->blocks as $block) {
            foreach ($block->rows as $row) {
                if (($row['number'] ?? null) === $number) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function testBuild_GivesTheNetSalesInEveryGrouping(): void
    {
        $this->sales();

        foreach (RevenueGroup::cases() as $group) {
            foreach ([false, true] as $details) {
                $this->assertSame('258.7500', $this->table($group, $details)->total['total'], $group->value . ($details ? ' in detail' : ' in summary'));
            }
        }
    }

    public function testBuild_RejectsTheThreeChecksAgainstTheBooksByNameWhenADocumentIsInAForeignCurrency(): void
    {
        $ids = $this->sales();

        (new Currencies())->setActive('USD', true);
        $this->accountingDb()->table('sales')->where('sale_id', $ids['second'])->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);

        $table = $this->table(RevenueGroup::Customer);

        $this->assertCount(3, $table->ties);

        foreach ($table->ties as $tie) {
            $this->assertTrue($tie->isRefused(), $tie->labelKey);
            $this->assertSame(['POS ' . $ids['second'] . ' (USD)'], $tie->refusalArguments);
        }

        $this->assertSame(
            ['Accounting_operational_reports.tie_sales_total', 'Accounting_operational_reports.tie_sales_tax', 'Accounting_operational_reports.tie_sales_revenue'],
            array_map(static fn ($tie): string => $tie->labelKey, $table->ties),
        );
    }

    public function testBuild_KeepsTheThreeChecksAgainstTheBooksWhileEveryDocumentIsInTheBaseCurrency(): void
    {
        $this->sales();

        foreach ($this->table(RevenueGroup::Customer)->ties as $tie) {
            $this->assertFalse($tie->isRefused(), $tie->labelKey);
            $this->assertTrue($tie->ties(), $tie->labelKey);
        }
    }

    public function testBuild_SplitsAnInvoiceByTheOneRule(): void
    {
        $ids   = $this->sales();
        $table = $this->table(RevenueGroup::Customer, true);
        $keys  = array_flip(['paid', 'unpaid', 'returned', 'total']);

        $this->assertEquals(['paid' => '115.0000', 'unpaid' => '0.0000', 'returned' => '0.0000', 'total' => '115.0000'], array_intersect_key($this->rowFor($table, $ids['first']), $keys));
        $this->assertEquals(['paid' => '28.7500', 'unpaid' => '172.5000', 'returned' => '28.7500', 'total' => '230.0000'], array_intersect_key($this->rowFor($table, $ids['second']), $keys));
        $this->assertEquals(['paid' => '0.0000', 'unpaid' => '-57.5000', 'returned' => '0.0000', 'total' => '-57.5000'], array_intersect_key($this->rowFor($table, $ids['note']), $keys));
        $this->assertEquals(['paid' => '0.0000', 'unpaid' => '-28.7500', 'returned' => '0.0000', 'total' => '-28.7500'], array_intersect_key($this->rowFor($table, $ids['refund']), $keys));
        $this->assertSame(['143.7500', '86.2500', '28.7500', '258.7500'], [$table->total['paid'], $table->total['unpaid'], $table->total['returned'], $table->total['total']]);
    }

    /**
     * @return array<string, string>
     */
    private function rowFor(ReportTable $table, int $saleId): array
    {
        foreach ($table->blocks as $block) {
            foreach ($block->rows as $row) {
                if (str_ends_with($row['number'], ' ' . $saleId) || $row['number'] === (string) $saleId) {
                    return $row;
                }
            }
        }

        $this->fail("The report has no row for the sale {$saleId}.");
    }

    public function testBuild_AddsEveryColumnUpToTheTotal(): void
    {
        $this->sales();

        $table = $this->table(RevenueGroup::Day);

        $this->assertSame($table->total['total'], bcadd(bcadd($table->total['paid'], $table->total['unpaid'], 4), $table->total['returned'], 4));
    }

    public function testBuild_CountsCreditAppliedToAnInvoiceAsPaid(): void
    {
        $ids   = $this->sales();
        $table = $this->table(RevenueGroup::Customer, true, customerId: $this->customerId);

        $this->assertSame(['15.0000'], array_map(fn (object $row): string => bcadd((string) $row->amount, '0', 4), $this->accountingDb()->table('customer_credit_applications')->where(['customer_id' => $this->customerId, 'released_at' => null])->get()->getResult()));
        $this->assertSame('115.0000', $this->rowFor($table, $ids['first'])['paid']);
    }

    public function testBuild_FillsEmptyDaysWithZeros(): void
    {
        $this->sales();

        $today = $this->today();
        $from  = date('Y-m-d', strtotime($today . ' -2 days'));
        $to    = date('Y-m-d', strtotime($today . ' +2 days'));
        $rows  = $this->table(RevenueGroup::Day, false, $from, $to)->blocks[0]->rows;

        $this->assertCount(5, $rows);
        $this->assertSame(array_map(static fn (int $offset): string => date('Y-m-d', strtotime($from . " +{$offset} days")), range(0, 4)), array_column($rows, 'period'));
        $this->assertSame(['0.0000', '0.0000', '258.7500', '0.0000', '0.0000'], array_column($rows, 'total'));
    }

    public function testBuild_DefaultsADailyReportToAMonthOfDays(): void
    {
        $table = $this->table(RevenueGroup::Day, false, null, '2026-10-27');

        $this->assertCount(31, $table->blocks[0]->rows);
        $this->assertSame('2026-09-27', $table->from);
        $this->assertSame('0.0000', $table->total['total']);
        $this->assertSame(0, $table->documents);
    }

    public function testBuild_StartsAWeekOnMonday(): void
    {
        $this->invoiceDated('115', '2026-10-25', null, $this->customerId);
        $this->invoiceDated('10', '2026-10-26', null, $this->customerId);

        $rows = $this->table(RevenueGroup::Week, false, '2026-10-19', '2026-11-01')->blocks[0]->rows;

        $this->assertSame([['2026-10-19', '2026-10-25'], ['2026-10-26', '2026-11-01']], array_map(static fn (array $row): array => [$row['period_from'], $row['period_to']], $rows));
        $this->assertSame(['115.0000', '10.0000'], array_column($rows, 'total'));
    }

    public function testBuild_FillsEmptyMonthsOfATwelveMonthDefault(): void
    {
        $rows = $this->table(RevenueGroup::Month, false, null, '2026-10-27')->blocks[0]->rows;

        $this->assertCount(12, $rows);
        $this->assertSame('2025-11', $rows[0]['period']);
        $this->assertSame('2026-10', $rows[11]['period']);
    }

    public function testBuild_GroupsByYearFromTheFirstDocument(): void
    {
        $this->invoiceDated('10', '2024-03-01', null, $this->customerId);
        $this->invoiceDated('20', '2026-03-01', null, $this->customerId);

        $rows = $this->table(RevenueGroup::Year, false, null, '2026-10-27')->blocks[0]->rows;

        $this->assertSame(['2024', '2025', '2026'], array_column($rows, 'period'));
        $this->assertSame(['10.0000', '0.0000', '20.0000'], array_column($rows, 'total'));
    }

    public function testBuild_FiltersByTheComposedState(): void
    {
        $this->sales();
        $this->invoiceDated('40', '2026-10-21', null, $this->anotherCustomer('Third', 'Buyer'));

        $total = fn (StatusFilter ...$statuses): string => $this->table(RevenueGroup::Customer, true, statuses: $statuses)->total['total'];

        $this->assertSame('115.0000', $total(StatusFilter::Paid));
        $this->assertSame('115.0000', $total(StatusFilter::PaidRefund));
        $this->assertSame('230.0000', $total(StatusFilter::Partial));
        $this->assertSame('40.0000', $total(StatusFilter::Unpaid));
        $this->assertSame('0.0000', $total(StatusFilter::UnpaidRefund));
        $this->assertSame('-57.5000', $total(StatusFilter::CreditNote));
        $this->assertSame('-28.7500', $total(StatusFilter::Refund));
        $this->assertSame('345.0000', $total(StatusFilter::Paid, StatusFilter::Partial));
        $this->assertSame('298.7500', $total());
    }

    public function testBuild_LimitsToACustomerAndToAnEmployee(): void
    {
        $this->sales();

        $this->assertSame('57.5000', $this->table(RevenueGroup::Customer, true, customerId: $this->customerId)->total['total']);
        $this->assertSame('201.2500', $this->table(RevenueGroup::Customer, true, customerId: $this->second)->total['total']);
        $this->assertSame('258.7500', $this->table(RevenueGroup::User, true, employeeId: $this->employeeId)->total['total']);
        $this->assertSame('0.0000', $this->table(RevenueGroup::User, true, employeeId: $this->employeeId + 1000)->total['total']);
    }

    public function testBuild_ShowsTheSalesWithoutTaxBetweenTheReturnedAndTheTotal(): void
    {
        $cycle = $this->referenceInvoice();
        $this->invoiceDated('10', '2026-10-21', null, $this->customerId);
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: '2026-10-21');

        $table = $this->table(RevenueGroup::Customer, false, notTaxed: true);

        $this->assertSame(['paid', 'unpaid', 'returned', 'not_taxed', 'total'], array_slice(array_column($table->columns, 'key'), 1));
        $this->assertSame('826.5000', $table->total['total']);
        $this->assertSame('720.0000', $table->total['not_taxed']);
        $this->assertNotContains('not_taxed', array_column($this->table(RevenueGroup::Customer)->columns, 'key'));
    }

    public function testBuild_AgreesWithTheJournalsOfTheSameDocuments(): void
    {
        $cycle = $this->referenceInvoice();
        $this->sales();
        $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')], SALE_TYPE_INVOICE, $this->customerId, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: '2026-10-21');

        $table = $this->table(RevenueGroup::Customer);

        $this->assertCount(3, $table->ties);
        $this->assertTrue($table->ties());

        foreach ($table->ties as $tie) {
            $this->assertSame('0.0000', $tie->difference(), $tie->labelKey);
        }

        $this->assertSame('106.5000', $table->ties[1]->ledger);
    }

    public function testBuild_ReadsNothingForAScopeOfNoBranch(): void
    {
        $this->sales();

        $table = (new RevenueReport())->build(ReportScope::resolve(null, []), RevenueGroup::Customer, false, '2026-10-01', '2026-10-31', '2026-10-31');

        $this->assertTrue($table->isEmpty());
        $this->assertSame('0.0000', $table->total['total']);
    }

    public function testBuild_LeavesOutADocumentThatWasCancelled(): void
    {
        $this->sales();
        $extra = $this->save([$this->line($this->item('0', false), 0, '1', '40')], [], [$this->payment(lang('Sales.cash'), '40')], SALE_TYPE_POS, $this->anotherCustomer('Third', 'Buyer'));

        $this->assertSame('298.7500', $this->table(RevenueGroup::Customer)->total['total']);
        $this->assertTrue($this->sales->delete($extra, false, true, $this->employeeId));

        $table = $this->table(RevenueGroup::Customer);

        $this->assertSame('258.7500', $table->total['total']);
        $this->assertSame(4, $table->documents);
        $this->assertTrue($table->ties());
    }

    public function testBuild_EqualsTheAccountOfSalesTheAccountOfTaxAndTheAccountOfTheCustomer(): void
    {
        $cycle = $this->referenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId);

        $table = $this->table(RevenueGroup::Customer, false, notTaxed: true);

        $this->assertSame('710.0000', $table->total['not_taxed']);
        $this->assertSame('-710.00', $this->balance($this->accountId('sales_revenue')));
        $this->assertSame('-106.50', $this->balance($this->accountId('tax_output')));
        $this->assertSame('816.50', $this->balance($this->customerAccount()));
        $this->assertSame('816.5000', $table->total['total']);
    }
}
