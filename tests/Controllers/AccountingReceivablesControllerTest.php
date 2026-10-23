<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of customer balances and aging: who may open it, what it lists, and that what it
 * shows is what the books hold.
 */
class AccountingReceivablesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
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
     * @param list<int>|null $branchIds
     */
    private function viewer(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_receivables'], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowsOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_receivables/search' . $query)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheTableToWhoeverHoldsTheModule(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables');

        $response->assertStatus(200);
        $response->assertSee('accounting_receivables');
        $response->assertSee(lang('Accounting_receivables.note'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables');
    }

    public function testGetIndex_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables');
    }

    public function testGetSearch_ListsTheCustomerWithWhatTheyOweAndHowOldItIs(): void
    {
        $old = $this->invoiceOf('70.00');
        $this->invoiceOf('30.00');
        $this->accountingDb()->table('sales')->where('sale_id', $old)->update(['sale_time' => date('Y-m-d 10:00:00', strtotime('-45 days'))]);
        $this->viewer();

        $result = $this->rowsOf();

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];

        $this->assertSame($this->customerId, $row['customer_id']);
        $this->assertSame('Omar Khaled', $row['customer_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('0.00', $row['credit']);
        $this->assertStringContainsString('30.00', $row['age_0_30']);
        $this->assertStringContainsString('70.00', $row['age_31_60']);
        $this->assertStringContainsString(lang('Accounting_receivables.agrees_yes'), $row['agrees']);
        $this->assertStringContainsString('accounting_receivables/customer/' . $this->customerId, $row['edit']);
    }

    public function testGetSearch_ShowsCreditAsANegativeBalance(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $this->creditNoteOf($invoice, '250.00');
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('-150.00', $row['balance']);
        $this->assertStringContainsString('150.00', $row['credit']);
        $this->assertStringContainsString('0.00', $row['open']);
    }

    public function testGetSearch_LeavesOutACustomerWhoseAccountIsEmpty(): void
    {
        $this->anotherCustomer();
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->viewer();

        $this->assertSame(['total' => 0, 'rows' => []], $this->rowsOf());
    }

    public function testGetSearch_FiltersByNameAndSortsByBalance(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $this->invoiceOf('100.00');
        $this->invoiceOf('300.00', $other);
        $this->viewer();

        $this->assertSame(['Hind Saad', 'Omar Khaled'], array_column($this->rowsOf('?sort=balance&order=desc')['rows'], 'customer_name'));
        $this->assertSame(['Omar Khaled', 'Hind Saad'], array_column($this->rowsOf('?sort=balance&order=asc')['rows'], 'customer_name'));
        $this->assertSame(['Hind Saad', 'Omar Khaled'], array_column($this->rowsOf('?sort=customer_name&order=asc')['rows'], 'customer_name'));
        $this->assertSame(['Hind Saad'], array_column($this->rowsOf('?search=hind')['rows'], 'customer_name'));
    }

    public function testGetSearch_PagesTheResult(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $this->invoiceOf('100.00');
        $this->invoiceOf('300.00', $other);
        $this->viewer();

        $page = $this->rowsOf('?sort=balance&order=desc&limit=1&offset=1');

        $this->assertSame(2, $page['total']);
        $this->assertSame(['Omar Khaled'], array_column($page['rows'], 'customer_name'));
    }

    public function testGetSearch_WarnsOfWhatAManualJournalPutOnTheAccountOfTheCustomer(): void
    {
        $this->invoiceOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->customerAccount(), debit: '25.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('125.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('25.00', $row['agrees']);
        $this->assertStringNotContainsString(lang('Accounting_receivables.agrees_yes'), $row['agrees']);
    }

    public function testGetSearch_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/search');
    }

    public function testGetCustomer_ListsTheInvoicesTheCreditAndWhatWasAppliedToWhat(): void
    {
        $first = $this->invoiceOf('100.00');
        $this->creditNoteOf($first, '250.00');
        $this->invoiceOf('40.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $response->assertStatus(200);
        $response->assertSee('Omar Khaled');
        $response->assertSee('id="receivable_aging"');
        $response->assertSee('id="receivable_invoices"');
        $response->assertSee('id="receivable_credits"');
        $response->assertSee('id="receivable_history"');
        $response->assertSee('POS ' . $first);
        $response->assertSee(lang('Accounting_receivables.source_credit_note'));
        $response->assertSee(lang('Accounting_receivables.agrees_yes'));
        $response->assertSee(lang('Sales.invoice_paid'));
        $response->assertSee(lang('Sales.invoice_returned_full'));
    }

    public function testGetCustomer_KeepsTheReleasedApplicationsInTheHistory(): void
    {
        $first  = $this->invoiceOf('70.00');
        $second = $this->invoiceOf('230.00');
        $paid   = $this->addPayment($second, '300.00');

        $this->removePayment($second, $paid);
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $response->assertSee('POS ' . $first);
        $this->assertSame(1, $this->accountingDb()->table('customer_credit_applications')->where('released_at IS NOT NULL')->countAllResults());
        $response->assertDontSee(lang('Accounting_receivables.no_history'));
    }

    public function testGetCustomer_IsNotFoundForACustomerWithoutAnAccount(): void
    {
        $without = $this->insertCustomer('Nora', 'Fahd');
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/customer/' . $without);
    }

    public function testGetCustomer_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/customer/' . $this->customerId);
    }

    public function testGetRow_GivesTheRowOfOneCustomer(): void
    {
        $this->invoiceOf('100.00');
        $this->viewer();

        $row = json_decode($this->get('/accounting_receivables/row/' . $this->customerId)->getJSON(), true);

        $this->assertSame('Omar Khaled', $row['customer_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
    }
}
