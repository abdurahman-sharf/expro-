<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\Portal\PortalStatement;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the portal says a customer owes: the same figures as the ledger of the customer, for that customer
 * alone.
 *
 * @internal
 */
final class PortalStatementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private int $other;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->other = $this->anotherCustomer();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function statement(?int $customerId = null): array
    {
        return (new PortalStatement())->of($customerId ?? $this->customerId, date('Y-m-d'));
    }

    public function testOf_TiesTheBalanceTheOpenAmountAndTheAgingToTheLedgerOfTheCustomer(): void
    {
        $this->invoiceDated('300.00', $this->daysAgo(45), 30);
        $this->invoiceDated('120.00', $this->daysAgo(2), 30);
        $position  = $this->position();
        $statement = $this->statement();

        $this->assertTrue($statement['available']);
        $this->assertSame('420.00 SAR', $statement['open']);
        $this->assertSame('420.00 SAR', $statement['balance']);
        $this->assertSame('0.00 SAR', $statement['credit']);
        $this->assertSame('300.00 SAR', $statement['overdue']);
        $this->assertSame(array_keys($position->aging(date('Y-m-d'))), array_keys($statement['aging']));
        $this->assertSame('120.00 SAR', $statement['aging']['today'] === '0.00 SAR' ? $statement['aging']['1-30'] : $statement['aging']['today']);
    }

    public function testOf_ListsTheOpenInvoicesWithTheirDueDatesAndHowLateTheyAre(): void
    {
        $late = $this->invoiceDated('300.00', $this->daysAgo(45), 30);
        $this->invoiceDated('120.00', $this->daysAgo(2), 30);

        $rows = $this->statement()['invoices'];

        $this->assertCount(2, $rows);
        $this->assertSame($late, $rows[0]['sale_id']);
        $this->assertSame(to_date(strtotime($this->daysAgo(45) . ' +30 days')), $rows[0]['due_date']);
        $this->assertSame(15, $rows[0]['days_overdue']);
        $this->assertSame(0, $rows[1]['days_overdue']);
        $this->assertSame('POS ' . $late, $rows[0]['label']);
    }

    public function testOf_LeavesOutAnInvoiceThatIsPaidInFull(): void
    {
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $open = $this->invoiceOf('40.00');

        $rows = $this->statement()['invoices'];

        $this->assertSame([$open], array_column($rows, 'sale_id'));
        $this->assertSame('40.00 SAR', $this->statement()['open']);
    }

    public function testOf_ShowsTheCreditTheCustomerHolds(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->creditNoteOf($invoice, '30.00');

        $statement = $this->statement();

        $this->assertSame('30.00 SAR', $statement['credit']);
        $this->assertSame('-30.00 SAR', $statement['balance']);
        $this->assertSame(lang('Portal.credit_note'), $statement['credits'][0]['kind']);
        $this->assertSame('30.00 SAR', $statement['credits'][0]['amount']);
    }

    public function testOf_LeavesOutCreditThatWasSpentOnAnInvoice(): void
    {
        $paid = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->creditNoteOf($paid, '30.00');
        $this->invoiceOf('50.00');

        $statement = $this->statement();

        $this->assertSame([], $statement['credits']);
        $this->assertSame('0.00 SAR', $statement['credit']);
        $this->assertSame('20.00 SAR', $statement['open']);
    }

    public function testOf_ShowsNothingOfAnotherCustomer(): void
    {
        $this->invoiceOf('555.00', $this->other);
        $this->invoiceOf('10.00');

        $statement = $this->statement();

        $this->assertSame('10.00 SAR', $statement['balance']);
        $this->assertCount(1, $statement['invoices']);
        $this->assertSame('555.00 SAR', $this->statement($this->other)['balance']);
    }

    public function testOf_IsAnEmptyStatementForACustomerWhoNeverBought(): void
    {
        $statement = $this->statement($this->insertCustomer('Nobody', 'Yet'));

        $this->assertTrue($statement['available']);
        $this->assertSame('0.00 SAR', $statement['balance']);
        $this->assertSame([], $statement['invoices']);
        $this->assertSame([], $statement['credits']);
    }

    public function testOf_SaysItIsNotAvailableWhileAccountingIsOff(): void
    {
        $this->invoiceOf('100.00');
        $this->setConfigValue('accounting_enabled', '0');

        $statement = $this->statement();

        $this->assertFalse($statement['available']);
        $this->assertSame([], $statement['invoices']);
        $this->assertSame('', $statement['balance']);
    }

    public function testOf_NamesAnOpeningBalanceAndADebitNoteAndGivesThemNoLinkToADocument(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $this->setConfigValue('accounting_debit_note', '1');
        $clerk = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        (new CustomerOpening())->create($this->customerId, 'owes_us', '1000.00', $this->daysAgo(30), 'before the books', null);
        $noteId = (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(5), 'amount' => '100', 'tax_percent' => '0', 'note' => 'freight recovered'], $clerk);

        $rows   = $this->statement()['invoices'];
        $byName = array_column($rows, 'sale_id', 'label');

        $this->assertCount(2, $rows);
        $this->assertNull($byName[lang('Portal.opening_balance')]);
        $this->assertNull($byName[lang('Portal.debit_note') . ' ' . (new SalesDebitNotes())->numberOf($noteId)]);
        $this->assertSame('1,100.00 SAR', $this->statement()['open']);

        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'sales_debit_note')->delete();
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();
        $this->accountingDb()->table('app_config')->whereIn('key', ['accounting_debit_note', 'accounting_customer_opening'])->delete();
    }
}
