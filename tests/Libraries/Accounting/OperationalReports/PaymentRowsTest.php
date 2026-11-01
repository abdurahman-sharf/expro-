<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\OperationalReports\PaymentRow;
use App\Libraries\Accounting\OperationalReports\PaymentRows;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The payments of customers whose journals stand, with no treasury in the books, so that the money goes into
 * the default account of the method: a payment into an account that holds no entity is read, the rounding of a
 * sale to the cash difference is not a payment, and a payment whose journal was reversed is not read.
 */
class PaymentRowsTest extends CIUnitTestCase
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

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @return list<PaymentRow>
     */
    private function read(): array
    {
        return (new PaymentRows())->read(ReportScope::resolve(null, null), '2020-01-01', date('Y-m-d'));
    }

    public function testRead_ReadsAPaymentIntoTheDefaultCashAccountThatHoldsNoEntity(): void
    {
        $this->save([$this->line($this->item('0', false), 0, '1', '10')], [], [$this->payment(lang('Sales.cash'), '10')], SALE_TYPE_INVOICE, $this->customerId);

        $rows = $this->read();

        $this->assertCount(1, $rows);
        $this->assertSame(['10.0000', '10.0000', 'cash'], [$rows[0]->amount, $rows[0]->ledgerMoney, $rows[0]->methodKey]);
        $this->assertNotSame('', $rows[0]->moneyAccount);
    }

    public function testRead_LeavesOutTheRoundingOfASaleToTheCashDifference(): void
    {
        $this->save([$this->line($this->item('0', false), 0, '1', '10')], [], [$this->payment(lang('Sales.cash'), '10'), $this->payment(lang('Sales.cash_adjustment'), '0.02', '0', 1)], SALE_TYPE_INVOICE, $this->customerId);

        $this->assertSame(['10.0000'], array_map(static fn (PaymentRow $row): string => $row->amount, $this->read()));
    }

    public function testRead_LeavesOutAPaymentWhoseJournalWasReversed(): void
    {
        $this->save([$this->line($this->item('0', false), 0, '1', '10')], [], [$this->payment(lang('Sales.cash'), '10')], SALE_TYPE_INVOICE, $this->customerId);

        $journal = $this->accountingDb()->table('accounting_journals')->select('journal_id')->where(['source_type' => 'sale_payment', 'status' => 'posted'])->get()->getRow();

        $this->assertCount(1, $this->read());

        (new JournalService())->reverseAutomatic((int) $journal->journal_id, date('Y-m-d'), 'Taken back', null);

        $this->assertSame([], $this->read());
    }

    public function testRead_ReadsNothingForAScopeOfNoBranch(): void
    {
        $this->save([$this->line($this->item('0', false), 0, '1', '10')], [], [$this->payment(lang('Sales.cash'), '10')], SALE_TYPE_INVOICE, $this->customerId);

        $this->assertSame([], (new PaymentRows())->read(ReportScope::resolve(null, []), '2020-01-01', date('Y-m-d')));
    }
}
