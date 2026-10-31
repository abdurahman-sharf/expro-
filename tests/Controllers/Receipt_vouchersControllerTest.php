<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\RevenueCategories;
use App\Libraries\Accounting\RevenuePostingBuilder;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * The screens of receipt vouchers: who may open them, what the form asks (no supplier, the voucher
 * number), that saving and deleting are POSTs that post to the routed account when none is
 * chosen and that the books may refuse in words.
 */
class Receipt_vouchersControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $treasuryId;
    private string $today;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();

        $this->treasuryId = $this->treasury('Revenue drawer');
        $this->today      = date('Y-m-d');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopExpenses();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();

        parent::tearDown();
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants($permissions, $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function voucher(array $changes = []): int
    {
        return (new RevenueVouchers())->save([...['date' => $this->today, 'amount' => '100.00', 'payment_type' => 'Cash', 'treasury_id' => $this->treasuryId, 'description' => 'Stand rent'], ...$changes], null, $this->clerkId);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheListToWhoeverHoldsTheModule(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $view = $this->get('/receipt_vouchers');

        $view->assertStatus(200);
        $view->assertSee(lang('Receipt_vouchers.new'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAsWith(['expenses']);

        $this->get('/receipt_vouchers')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAsWith(['receipt_vouchers']);
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/receipt_vouchers');
    }

    public function testGetSearch_ListsTheVouchersOfTheBranchesTheReaderMaySeeWithTheirFilters(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $branch   = $this->createBranch();
        $treasury = $this->treasury('Branch drawer', $branch);
        $first    = $this->voucher(['description' => 'January rent', 'date' => '2026-01-10']);
        $second   = $this->voucher(['treasury_id' => $treasury, 'description' => 'Other branch', 'date' => '2026-02-10']);
        $ids      = fn (string $query): array => array_map(static fn (array $row): int => (int) $row['revenue_id'], json_decode($this->get('/receipt_vouchers/search' . $query)->getJSON(), true)['rows']);

        $this->assertSame([$second, $first], $ids(''));
        $this->assertSame([$first], $ids('?search=January'));
        $this->assertSame([$second], $ids('?start_date=2026-02-01&end_date=2026-02-28'));

        $this->actAsWith(['receipt_vouchers'], [1]);

        $this->assertSame([$first], $ids(''));
    }

    public function testGetSearch_ShowsTheNumberTheAmountAndTheTaxOfEachVoucher(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $manager = $this->employeeWithGrants(['config']);

        (new DocumentNumbering())->configure(DocumentType::Revenue, ['enabled' => true, 'prefix' => 'RV-', 'padding' => 3], $manager);

        $this->voucher(['amount' => '115.00', 'tax_amount' => '15.00']);

        $row = json_decode($this->get('/receipt_vouchers/search')->getJSON(), true)['rows'][0];

        $this->assertSame('RV-001', strip_tags($row['revenue_number']));
        $this->assertStringContainsString('115.00', strip_tags($row['amount']));
        $this->assertStringContainsString('15.00', strip_tags($row['tax_amount']));
    }

    public function testGetView_HasNoSupplierBlockAndShowsTheVoucherNumberLabel(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $view = $this->get('/receipt_vouchers/view');

        $view->assertStatus(200);
        $view->assertSee(lang('Receipt_vouchers.number'));
        $view->assertSee(lang('Receipt_vouchers.number_automatic'));
        $view->assertDontSee('supplier');
        $view->assertDontSee('name="supplier_id"');
        $view->assertSee('name="tax_amount"');
        $view->assertSee('name="payment_type"');
        $view->assertSee('name="treasury_id"');
        $view->assertDontSee('value="Due"');
    }

    public function testGetView_ShowsTheNextNumberOfTheSequenceBeforeTheVoucherIsSaved(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $manager = $this->employeeWithGrants(['config']);

        (new DocumentNumbering())->configure(DocumentType::Revenue, ['enabled' => true, 'prefix' => 'RV-', 'padding' => 3], $manager);

        $this->get('/receipt_vouchers/view')->assertSee('RV-001');
    }

    public function testGetView_ShowsAVoucherInItsFormAndRefusesOneOfAnotherBranch(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $id = $this->voucher(['description' => 'Kept text']);

        $this->get('/receipt_vouchers/view/' . $id)->assertSee('Kept text');

        $other = $this->createBranch();

        $this->actAsWith(['receipt_vouchers'], [$other]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/receipt_vouchers/view/' . $id);
    }

    public function testPostSave_SavesAVoucherThatDefaultsTheAccountToTheRoutedOneWhenNoneIsChosen(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $saved = $this->postJson('/receipt_vouchers/save', ['date' => $this->today, 'amount' => '100.00', 'tax_amount' => '0', 'payment_type' => 'Cash', 'treasury_id' => $this->treasuryId, 'category_id' => 0, 'account_id' => 0, 'description' => 'No account chosen']);

        $this->assertTrue($saved['success']);
        $this->assertSame(lang('Receipt_vouchers.successful_adding'), $saved['message']);

        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'source_id' => $saved['id']])->get()->getRow();
        $credit  = $this->accountingDb()->table('accounting_journal_lines')->where(['journal_id' => $journal->journal_id])->where('credit >', 0)->get()->getRow();

        $this->assertSame($this->accountId('revenues_default'), (int) $credit->account_id);
    }

    public function testPostSave_ChangesAVoucherAndSaysSo(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $id      = $this->voucher();
        $changed = $this->postJson('/receipt_vouchers/save/' . $id, ['date' => $this->today, 'amount' => '60.00', 'tax_amount' => '0', 'payment_type' => 'Cash', 'treasury_id' => $this->treasuryId, 'description' => 'Smaller']);

        $this->assertTrue($changed['success']);
        $this->assertSame(lang('Receipt_vouchers.successful_updating'), $changed['message']);
        $this->assertSame('60.0000', (new RevenueVouchers())->find($id)->amount);
    }

    public function testPostSave_SaysInWordsWhatTheBooksRefuse(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $bad = $this->postJson('/receipt_vouchers/save', ['date' => $this->today, 'amount' => '0', 'payment_type' => 'Cash', 'treasury_id' => $this->treasuryId]);

        $this->assertFalse($bad['success']);
        $this->assertSame(lang('Accounting.error_revenue_amount_invalid'), $bad['message']);

        $this->closeThrough($this->today);

        $closed = $this->postJson('/receipt_vouchers/save', ['date' => $this->today, 'amount' => '10.00', 'payment_type' => 'Cash', 'treasury_id' => $this->treasuryId]);

        $this->assertFalse($closed['success']);
        $this->assertNotSame('', $closed['message']);
        $this->assertSame(0, $this->countRows('revenues'));
    }

    public function testPostSave_RefusesAVoucherOfAnotherBranch(): void
    {
        $id    = $this->voucher();
        $other = $this->createBranch();

        $this->actAsWith(['receipt_vouchers'], [$other]);

        $this->expectException(PageNotFoundException::class);

        $this->post('/receipt_vouchers/save/' . $id, ['date' => $this->today, 'amount' => '5.00', 'payment_type' => 'Cash']);
    }

    public function testPostDelete_DeletesTheVouchersAndReversesTheirJournals(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $first  = $this->voucher();
        $second = $this->voucher(['amount' => '20.00']);

        $deleted = $this->postJson('/receipt_vouchers/delete', ['ids' => [(string) $first, (string) $second]]);

        $this->assertTrue($deleted['success']);
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'status' => 'reversed'])->countAllResults());
        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($this->treasuryId));
        $this->assertSame([], json_decode($this->get('/receipt_vouchers/search')->getJSON(), true)['rows']);
        $this->assertCount(2, json_decode($this->get('/receipt_vouchers/search?deleted=1')->getJSON(), true)['rows']);
    }

    public function testPostDelete_RefusesTheWholeListWhenOneVoucherBelongsToAnotherBranch(): void
    {
        $mine  = $this->voucher();
        $other = $this->createBranch();
        $theirs = $this->voucher(['treasury_id' => $this->treasury('Branch drawer', $other)]);

        $this->actAsWith(['receipt_vouchers'], [1]);

        try {
            $this->post('/receipt_vouchers/delete', ['ids' => [(string) $mine, (string) $theirs]]);
            $this->fail('A voucher of another branch was deleted');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([0, 0], [(int) (new RevenueVouchers())->find($mine)->deleted, (int) (new RevenueVouchers())->find($theirs)->deleted]);
    }

    public function testPostSaveAndPostDelete_AreNeverReachedByALink(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $id = $this->voucher();

        foreach (['/receipt_vouchers/delete', '/receipt_vouchers/save', '/receipt_vouchers/save/' . $id] as $path) {
            try {
                $this->get($path);
                $this->fail('A GET reached ' . $path);
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('0', (string) (new RevenueVouchers())->find($id)->deleted);
    }

    public function testRevenueCategories_FeedTheFormAndTheCategoryAccountDecidesTheJournal(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $account  = $this->customAccount('42', '4210', 'Rent income');
        $category = (new RevenueCategories())->save(['category_name' => 'Rent', 'account_id' => $account], null, $this->employeeWithGrants(['acc_routing_manage']));

        $this->get('/receipt_vouchers/view')->assertSee('Rent');

        $saved = $this->postJson('/receipt_vouchers/save', ['date' => $this->today, 'amount' => '100.00', 'payment_type' => 'Cash', 'treasury_id' => $this->treasuryId, 'category_id' => $category]);

        $this->assertTrue($saved['success']);
        $this->assertSame('-100.00', $this->balanceOf($account));
    }

    public function testGetView_IsNotFoundForADeletedVoucher(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $id = $this->voucher();

        (new RevenueVouchers())->delete([$id], $this->clerkId);

        $this->expectException(PageNotFoundException::class);

        $this->get('/receipt_vouchers/view/' . $id);
    }

    public function testPostSave_KeepsTheTreasuryOfAVoucherWhenTheFormDoesNotSendOne(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $second  = $this->treasury('Second drawer');
        $id      = $this->voucher(['treasury_id' => $second]);
        $changed = $this->postJson('/receipt_vouchers/save/' . $id, ['date' => $this->today, 'amount' => '60.00', 'tax_amount' => '0', 'payment_type' => 'Cash', 'description' => 'Smaller']);

        $this->assertTrue($changed['success']);
        $this->assertSame($second, (int) (new RevenueVouchers())->find($id)->treasury_id);
    }
}
