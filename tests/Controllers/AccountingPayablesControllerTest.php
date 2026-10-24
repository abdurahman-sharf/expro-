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
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of supplier balances and aging: who may open it, what it lists, and that what it
 * shows is what the books hold.
 */
class AccountingPayablesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

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

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function viewer(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_payables'], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowsOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_payables/search' . $query)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheTableToWhoeverHoldsTheModule(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_payables');

        $response->assertStatus(200);
        $response->assertSee('accounting_payables');
        $response->assertSee(lang('Accounting_payables.note'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_receivables']), 'menu_group' => 'home']);

        $this->get('/accounting_payables')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables');
    }

    public function testGetIndex_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables');
    }

    public function testGetSearch_ListsTheSupplierWithWhatIsOwedToThemAndHowOldItIs(): void
    {
        $old = $this->purchaseOf('70.00');
        $this->purchaseOf('30.00');
        $this->accountingDb()->table('receivings')->where('receiving_id', $old)->update(['receiving_time' => date('Y-m-d 10:00:00', strtotime('-45 days'))]);
        $this->viewer();

        $result = $this->rowsOf();

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];

        $this->assertSame($this->supplierId, $row['supplier_id']);
        $this->assertSame('Gulf Supplies', $row['supplier_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('0.00', $row['credit']);
        $this->assertStringContainsString('30.00', $row['age_0_30']);
        $this->assertStringContainsString('70.00', $row['age_31_60']);
        $this->assertStringContainsString(lang('Accounting_payables.agrees_yes'), $row['agrees']);
        $this->assertStringContainsString('accounting_payables/supplier/' . $this->supplierId, $row['edit']);
    }

    public function testGetSearch_ShowsCreditHeldWithTheSupplierAsANegativeBalance(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $this->debitNoteOf($purchase, '40.00');
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('-40.00', $row['balance']);
        $this->assertStringContainsString('40.00', $row['credit']);
        $this->assertStringContainsString('0.00', $row['open']);
    }

    public function testGetSearch_LeavesOutASupplierWhoseAccountIsEmpty(): void
    {
        $this->anotherSupplier();
        $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $this->viewer();

        $this->assertSame(['total' => 0, 'rows' => []], $this->rowsOf());
    }

    public function testGetSearch_FiltersByNameAndSortsByBalance(): void
    {
        $other = $this->anotherSupplier('Najd Trading');

        $this->purchaseOf('100.00');
        $this->purchaseOf('300.00', $other);
        $this->viewer();

        $this->assertSame(['Najd Trading', 'Gulf Supplies'], array_column($this->rowsOf('?sort=balance&order=desc')['rows'], 'supplier_name'));
        $this->assertSame(['Gulf Supplies', 'Najd Trading'], array_column($this->rowsOf('?sort=balance&order=asc')['rows'], 'supplier_name'));
        $this->assertSame(['Gulf Supplies', 'Najd Trading'], array_column($this->rowsOf('?sort=supplier_name&order=asc')['rows'], 'supplier_name'));
        $this->assertSame(['Najd Trading'], array_column($this->rowsOf('?search=najd')['rows'], 'supplier_name'));
    }

    public function testGetSearch_PagesTheResult(): void
    {
        $other = $this->anotherSupplier('Najd Trading');

        $this->purchaseOf('100.00');
        $this->purchaseOf('300.00', $other);
        $this->viewer();

        $page = $this->rowsOf('?sort=balance&order=desc&limit=1&offset=1');

        $this->assertSame(2, $page['total']);
        $this->assertSame(['Gulf Supplies'], array_column($page['rows'], 'supplier_name'));
    }

    public function testGetSearch_WarnsOfWhatAManualJournalPutOnTheAccountOfTheSupplier(): void
    {
        $this->purchaseOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '25.00'), new JournalLineDraft($this->supplierAccount(), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('125.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('25.00', $row['agrees']);
        $this->assertStringNotContainsString(lang('Accounting_payables.agrees_yes'), $row['agrees']);
    }

    public function testGetSearch_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/search');
    }

    public function testGetSupplier_ListsThePurchasesTheCreditAndWhatWasAppliedToWhat(): void
    {
        $first = $this->purchaseOf('100.00');
        $this->debitNoteOf($first, '100.00');
        $this->purchaseOf('40.00');
        $this->viewer();

        $response = $this->get('/accounting_payables/supplier/' . $this->supplierId);

        $response->assertStatus(200);
        $response->assertSee('Gulf Supplies');
        $response->assertSee('id="payable_aging"');
        $response->assertSee('id="payable_purchases"');
        $response->assertSee('id="payable_credits"');
        $response->assertSee('id="payable_history"');
        $response->assertSee('RECV ' . $first);
        $response->assertSee(lang('Accounting_payables.source_debit_note'));
        $response->assertSee(lang('Accounting_payables.agrees_yes'));
        $response->assertSee(lang('Sales.invoice_paid'));
        $response->assertSee(lang('Sales.invoice_returned_full'));
    }

    public function testGetSupplier_KeepsTheReleasedApplicationsInTheHistory(): void
    {
        $first   = $this->purchaseOf('70.00');
        $second  = $this->purchaseOf('230.00');
        $payment = $this->payPurchase($second, '300.00');

        $this->assertTrue($this->changePayment($second, $payment, ['payment_amount' => '0']));
        $this->viewer();

        $response = $this->get('/accounting_payables/supplier/' . $this->supplierId);

        $response->assertSee('RECV ' . $first);
        $this->assertSame(1, $this->accountingDb()->table('supplier_credit_applications')->where('released_at IS NOT NULL')->countAllResults());
        $response->assertDontSee(lang('Accounting_payables.no_history'));
    }

    public function testGetSupplier_IsNotFoundForASupplierWithoutAnAccount(): void
    {
        $without = $this->insertSupplier('Bare Supplier');
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/supplier/' . $without);
    }

    public function testGetSupplier_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/supplier/' . $this->supplierId);
    }

    public function testGetRow_GivesTheRowOfOneSupplier(): void
    {
        $this->purchaseOf('100.00');
        $this->viewer();

        $row = json_decode($this->get('/accounting_payables/row/' . $this->supplierId)->getJSON(), true);

        $this->assertSame('Gulf Supplies', $row['supplier_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
    }
}
