<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\EntityAccountService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingControllerFixtureTrait;

/**
 * The search the pickers ask while someone types: twenty entries at most, by kind, in the branches of the
 * employee, and only to someone whose screens use that kind.
 *
 * @internal
 */
final class AccountingLookupControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function search(array $query): array
    {
        return $this->jsonOf($this->get('/accounting_lookup/search?' . http_build_query($query)));
    }

    private function accountant(): int
    {
        $personId = $this->employeeWithGrants(['accounting_journals']);
        $this->actAs($personId);

        return $personId;
    }

    public function testGetSearch_ReturnsAtMostTwentyAccountsAndSaysThereAreMore(): void
    {
        $this->accountant();

        for ($index = 1; $index <= 22; $index++) {
            $this->customAccount('11', sprintf('119%03d', $index), 'Lookup account ' . $index);
        }

        $found = $this->search(['kind' => 'account', 'mode' => 'postable', 'term' => 'Lookup account']);

        $this->assertCount(20, $found['results']);
        $this->assertTrue($found['more']);
        $this->assertSame(20, $found['limit']);
        $this->assertSame(['id', 'code', 'label'], array_keys($found['results'][0]));
        $this->assertSame('119001 - Lookup account 1', $found['results'][0]['label']);
    }

    public function testGetSearch_NarrowsByTheTermGiven(): void
    {
        $this->accountant();
        $id = $this->customAccount('11', '119900', 'Lookup unique petty box');

        $this->customAccount('11', '119901', 'Lookup other drawer');

        $found = $this->search(['kind' => 'account', 'mode' => 'postable', 'term' => 'petty']);

        $this->assertSame([$id], array_column($found['results'], 'id'));
        $this->assertFalse($found['more']);
    }

    public function testGetSearch_ReturnsTheFirstEntriesForAnEmptyTerm(): void
    {
        $this->accountant();

        $found = $this->search(['kind' => 'account', 'mode' => 'postable', 'term' => '']);

        $this->assertCount(20, $found['results']);
        $this->assertTrue($found['more']);
    }

    public function testGetSearch_RejectsAKindOrAModeItDoesNotKnow(): void
    {
        $this->accountant();

        $this->get('/accounting_lookup/search?kind=employee&mode=postable')->assertStatus(400);
        $this->get('/accounting_lookup/search?kind=account&mode=secret')->assertStatus(400);
        $this->get('/accounting_lookup/search')->assertStatus(400);
    }

    public function testGetSearch_RejectsAQueryValueThatIsAnArray(): void
    {
        $this->accountant();

        $this->get('/accounting_lookup/search?kind[]=account&mode=postable')->assertStatus(400);
        $this->get('/accounting_lookup/search?kind=account&mode[]=postable')->assertStatus(400);
        $this->get('/accounting_lookup/search?kind=account&mode=postable&term[]=cash')->assertStatus(400);
        $this->get('/accounting_lookup/search?kind=account&mode=postable')->assertStatus(200);
    }

    public function testGetSearch_RejectsAnAccountSearchToSomeoneWhoseScreensNeverListAccounts(): void
    {
        $this->actAs($this->employeeWithGrants(['customers']));

        $this->get('/accounting_lookup/search?kind=account&mode=postable')->assertStatus(403);
    }

    public function testGetSearch_ServesCustomersToTheScreensOfCustomersAndSuppliersToTheScreensOfSuppliers(): void
    {
        $customer = $this->insertCustomer('Lookup', 'Buyer');
        $supplier = $this->insertSupplier('Lookup Wholesale');

        $this->actAs($this->employeeWithGrants(['accounting_receivables']));

        $this->assertSame([$customer], array_column($this->search(['kind' => 'customer', 'mode' => 'every', 'term' => 'Lookup'])['results'], 'id'));
        $this->get('/accounting_lookup/search?kind=supplier&mode=every&term=Lookup')->assertStatus(403);

        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->assertSame([$supplier], array_column($this->search(['kind' => 'supplier', 'mode' => 'every', 'term' => 'Lookup'])['results'], 'id'));
        $this->get('/accounting_lookup/search?kind=customer&mode=every&term=Lookup')->assertStatus(403);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reportsThatFilterByCustomer(): array
    {
        return [
            'customer reports' => ['reports_customers'],
            'payments reports' => ['reports_payments'],
            'sales reports'    => ['reports_sales'],
        ];
    }

    #[DataProvider('reportsThatFilterByCustomer')]
    public function testGetSearch_ServesTheCustomersOfTheLedgerToTheReaderOfAReportThatFiltersByCustomer(string $permission): void
    {
        $customer = $this->insertCustomer('Ledger', 'Searchable');
        $deleted  = $this->insertCustomer('Ledger', 'Searchable Departed');
        $this->insertCustomer('Ledger', 'Searchable Unbooked');
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customer, 'Ledger Searchable');
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $deleted, 'Ledger Searchable Departed');
        $this->accountingDb()->table('customers')->where('person_id', $deleted)->update(['deleted' => 1]);

        $this->actAs($this->employeeWithGrants(['accounting_reports', $permission]));

        $found = $this->search(['kind' => 'customer', 'mode' => 'ledger', 'term' => 'Ledger Searchable']);

        $this->assertEqualsCanonicalizing([$customer, $deleted], array_column($found['results'], 'id'));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function grantsThatNeverReadACustomerReport(): array
    {
        return [
            'selling'                   => [['sales', 'sales_change_price']],
            'customers'                 => [['customers']],
            'receivables'               => [['accounting_receivables']],
            'selling and receivables'   => [['sales', 'sales_change_price', 'customers', 'accounting_receivables']],
            'reports of other subjects' => [['accounting_reports', 'reports_items', 'reports_employees', 'reports_categories']],
        ];
    }

    /**
     * @param list<string> $grants
     */
    #[DataProvider('grantsThatNeverReadACustomerReport')]
    public function testGetSearch_RejectsTheCustomersOfTheLedgerToSomeoneWhoReadsNoReportThatFiltersByCustomer(array $grants): void
    {
        $departed = $this->insertCustomer('Ledger', 'Departed');
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $departed, 'Ledger Departed');
        $this->accountingDb()->table('customers')->where('person_id', $departed)->update(['deleted' => 1]);

        $this->actAs($this->employeeWithGrants($grants));

        $this->get('/accounting_lookup/search?kind=customer&mode=ledger&term=Ledger')->assertStatus(403);
    }

    public function testGetSearch_LeavesTheDeletedCustomersOutOfTheEveryModeThatTheScreensOfSellingUse(): void
    {
        $live     = $this->insertCustomer('Ledger', 'Live Buyer');
        $departed = $this->insertCustomer('Ledger', 'Live Departed');
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $departed, 'Ledger Live Departed');
        $this->accountingDb()->table('customers')->where('person_id', $departed)->update(['deleted' => 1]);

        $this->actAs($this->employeeWithGrants(['sales', 'sales_change_price']));

        $this->assertSame([$live], array_column($this->search(['kind' => 'customer', 'mode' => 'every', 'term' => 'Ledger Live'])['results'], 'id'));
    }

    public function testGetSearch_RejectsTheEveryModeOfCustomersToTheReaderOfTheReportsWhoHoldsNothingElse(): void
    {
        $this->insertCustomer('Ledger', 'Every Mode');

        $this->actAs($this->employeeWithGrants(['accounting_reports', 'reports_customers', 'reports_payments', 'reports_sales']));

        $this->get('/accounting_lookup/search?kind=customer&mode=every&term=Ledger')->assertStatus(403);
    }

    public function testGetSearch_RejectsTheLedgerModeForAKindThatIsNotACustomer(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals', 'accounting_payables', 'accounting_receivables', 'accounting_reports', 'reports_customers']));

        $this->get('/accounting_lookup/search?kind=supplier&mode=ledger')->assertStatus(400);
        $this->get('/accounting_lookup/search?kind=account&mode=ledger')->assertStatus(400);
        $this->get('/accounting_lookup/search?kind=customer&mode=ledger')->assertStatus(200);
    }

    public function testGetSearch_LeavesOutTheStockAccountOfALocationInABranchTheEmployeeDoesNotWorkIn(): void
    {
        $second  = $this->createBranch('LK2', 'Lookup branch');
        $own     = $this->insertStockLocation('Lookup own depot');
        $foreign = $this->insertStockLocation('Lookup foreign depot');

        $this->accountingDb()->table('stock_locations')->where('location_id', $foreign)->update(['branch_id' => $second]);
        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $own, 'Lookup own depot');
        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $foreign, 'Lookup foreign depot');

        $this->actAs($this->employeeWithGrants(['accounting_journals'], [1]));
        $limited = array_column($this->search(['kind' => 'account', 'mode' => 'postable', 'term' => 'Lookup'])['results'], 'label');

        $this->assertCount(1, $limited);
        $this->assertStringContainsString('Lookup own depot', $limited[0]);

        $this->actAs($this->employeeWithGrants(['accounting_journals']));

        $this->assertCount(2, $this->search(['kind' => 'account', 'mode' => 'postable', 'term' => 'Lookup'])['results']);
    }

    public function testGetSearch_IsNotFoundWhileAccountingIsDisabled(): void
    {
        $this->prepareAccounting(false);
        $this->actAs($this->employeeWithGrants(['accounting_journals']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_lookup/search?kind=account&mode=postable');
    }

    public function testGetSearch_RedirectsSomeoneWhoHoldsNoModuleThatUsesAPicker(): void
    {
        $this->actAs($this->employeeWithGrants(['items']));

        $this->get('/accounting_lookup/search?kind=account&mode=postable')->assertRedirect();
    }
}
