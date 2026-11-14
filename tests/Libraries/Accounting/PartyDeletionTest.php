<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\PartyDeletion;
use App\Libraries\Accounting\SupplierAdvances;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Whether a customer or a supplier may be deleted: only when the books say they hold nothing, whether it
 * is an invoice or a purchase still open, credit not yet spent, an opening balance or a balance on the
 * account, and always while accounting is off.
 */
class PartyDeletionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PartyDeletion $deletion;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->deletion = new PartyDeletion();
        $this->manager  = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::PAYABLES_MANAGE, AccountingGuard::OPENING_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

        $this->setConfigValue('accounting_customer_opening', '0');
        $this->stopBuying();

        parent::tearDown();
    }

    public function testUnsettled_IsNullForACustomerWithNothingOnTheBooks(): void
    {
        $this->assertNull($this->deletion->unsettled(PartyDeletion::CUSTOMER, $this->anotherCustomer()));
        $this->assertNull($this->deletion->unsettled(PartyDeletion::CUSTOMER, $this->insertCustomer('No', 'Account')));
    }

    public function testUnsettled_IsNullForACustomerWhoseInvoicesAreAllPaid(): void
    {
        $this->invoiceOf('100.00', null, [$this->payment('Cash', '100.00')]);

        $this->assertNull($this->deletion->unsettled(PartyDeletion::CUSTOMER, $this->customerId));
    }

    public function testUnsettled_NamesWhatAnOpenInvoiceHolds(): void
    {
        $this->invoiceOf('100.00');
        $this->invoiceOf('40.00', null, [$this->payment('Cash', '15.00')]);

        $this->assertSame('125.0000', $this->deletion->unsettled(PartyDeletion::CUSTOMER, $this->customerId));
    }

    public function testUnsettled_NamesWhatAnAdvanceNotYetSpentHolds(): void
    {
        (new CustomerAdvances())->receive($this->customerId, '300.00', date('Y-m-d'), 'Cash', null, '', '', $this->manager);

        $this->assertSame('300.0000', $this->deletion->unsettled(PartyDeletion::CUSTOMER, $this->customerId));
    }

    public function testUnsettled_NamesAnOpeningBalanceACustomerOwes(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');

        (new CustomerOpening())->create($this->customerId, 'owes_us', '750.00', date('Y-m-d'), '', $this->manager);

        $this->assertSame('750.0000', $this->deletion->unsettled(PartyDeletion::CUSTOMER, $this->customerId));
    }

    public function testUnsettled_IsNullWhileAccountingIsOffWhateverTheAccountHolds(): void
    {
        $this->invoiceOf('100.00');
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertNull($this->deletion->unsettled(PartyDeletion::CUSTOMER, $this->customerId));
        $this->assertNull($this->deletion->unsettled(PartyDeletion::SUPPLIER, $this->supplierId));
    }

    public function testUnsettled_NamesWhatAPurchaseOnCreditStillOwes(): void
    {
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($this->item('0.00'), 0, '3', '90')], -1, lang('Sales.due')));

        $this->assertSame('270.0000', $this->deletion->unsettled(PartyDeletion::SUPPLIER, $this->supplierId));
    }

    public function testUnsettled_IsNullForASupplierWhoseOnlyPurchaseWasPaidAtOnce(): void
    {
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($this->item('0.00'), 0, '3', '90')], -1, 'Cash'));

        $this->assertNull($this->deletion->unsettled(PartyDeletion::SUPPLIER, $this->supplierId));
    }

    public function testUnsettled_NamesWhatWasPaidToASupplierAheadOfAnyPurchase(): void
    {
        (new SupplierAdvances())->pay($this->supplierId, '120.00', date('Y-m-d'), 'Cash', null, '', '', $this->manager);

        $this->assertSame('120.0000', $this->deletion->unsettled(PartyDeletion::SUPPLIER, $this->supplierId));
    }

    public function testUnsettled_NamesAnOpeningBalanceWeOweASupplier(): void
    {
        $this->receivingIds[] = (new SupplierAdvances())->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '85.50', date('Y-m-d'), '', $this->manager);

        $this->assertSame('85.5000', $this->deletion->unsettled(PartyDeletion::SUPPLIER, $this->supplierId));
    }

    public function testDeleteIfSettled_RunsTheDeletionOfAPartyThatHoldsNothingUnderTheLockOfTheParty(): void
    {
        $lock   = 'ospos_customer_' . substr(sha1($this->accountingDb()->getDatabase()), 0, 10) . '_' . $this->customerId;
        $held   = null;
        $result = $this->deletion->deleteIfSettled(PartyDeletion::CUSTOMER, $this->customerId, function () use ($lock, &$held): bool {
            $held = (int) $this->accountingDb()->query('SELECT IS_FREE_LOCK(?) AS free', [$lock])->getRow()->free;

            return true;
        });

        $this->assertSame(['deleted' => true, 'amount' => null], $result);
        $this->assertSame(0, $held, 'The lock of the customer was taken while the deletion ran');
    }

    public function testDeleteIfSettled_DoesNotRunTheDeletionOfAPartyThatHoldsSomethingAndSaysHowMuch(): void
    {
        $this->invoiceOf('100.00');

        $ran    = false;
        $result = $this->deletion->deleteIfSettled(PartyDeletion::CUSTOMER, $this->customerId, function () use (&$ran): bool {
            $ran = true;

            return true;
        });

        $this->assertSame(['deleted' => false, 'amount' => '100.0000'], $result);
        $this->assertFalse($ran);
    }

    public function testDeleteIfSettled_DeletesASupplierThatHoldsNothingAndRefusesOneThatDoes(): void
    {
        $settled = $this->supplier('Settled Supplies');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($this->item('0.00'), 0, '1', '50')], $this->supplierId, lang('Sales.due')));

        $this->assertSame(['deleted' => true, 'amount' => null], $this->deletion->deleteIfSettled(PartyDeletion::SUPPLIER, $settled, static fn (): bool => true));
        $this->assertSame(['deleted' => false, 'amount' => '50.0000'], $this->deletion->deleteIfSettled(PartyDeletion::SUPPLIER, $this->supplierId, static fn (): bool => true));
    }
}
