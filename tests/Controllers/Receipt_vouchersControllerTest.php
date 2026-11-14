<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\RevenueCategories;
use App\Libraries\Accounting\RevenuePostingBuilder;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Integrations\PaymentMethods;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;

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
    use DatabaseFingerprintTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use IntegrationFixtureTrait;

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
        $this->wipeIntegration();
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

    private function treasurySelect(string $body): string
    {
        $this->assertSame(1, preg_match('/<select name="treasury_id".*?<\/select>/s', $body, $match), 'The form has a treasury select');

        return $match[0];
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

        $company = (string) $this->accountingDb()->table('companies')->select('name')->where('company_id', 1)->get()->getRow()->name;

        $this->assertFalse($closed['success']);
        $this->assertSame(lang('Accounting.posting_period_closed', [$this->today, $this->today, $company]), $closed['message']);
        $this->assertStringContainsString($company, $closed['message'], 'The refusal is the closed period one, in words, and not a missing translation');
        $this->assertSame(0, $this->countRows('revenues'));
    }

    public function testPostSave_RejectsAVoucherOfAnotherBranch(): void
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

    public function testPostDelete_RejectsTheWholeListWhenOneVoucherBelongsToAnotherBranch(): void
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

    public function testPostSave_IsNeverReachedByALinkNorIsPostDelete(): void
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

    public function testPostSave_CreditsTheAccountOfTheCategoryTheFormOffers(): void
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

    public function testPostSave_KeepsTheTreasuryWhenTheFormPostsTheDefaultOptionOfAVoucherThatHasOneOrNone(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $second = $this->treasury('Second drawer');
        $named  = $this->voucher(['treasury_id' => $second]);
        $bare   = (new RevenueVouchers())->save(['date' => $this->today, 'amount' => '100.00', 'payment_type' => 'Cash', 'description' => 'No treasury'], null, $this->clerkId);

        (new TreasuryService())->setDefaultFor($this->clerkId, $this->treasuryId);

        foreach ([$named, $bare] as $id) {
            $changed = $this->postJson('/receipt_vouchers/save/' . $id, ['date' => $this->today, 'amount' => '100.00', 'tax_amount' => '0', 'payment_type' => 'Cash', 'treasury_id' => '0', 'description' => 'Fixed text']);

            $this->assertTrue($changed['success']);
        }

        $this->assertSame($second, (int) (new RevenueVouchers())->find($named)->treasury_id);
        $this->assertNull((new RevenueVouchers())->find($bare)->treasury_id);
    }

    public function testGetView_OffersTheTreasuryTheCategoryAndTheAccountAVoucherHasWhenTheyCannotBeChosenAnyMore(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $account  = $this->customAccount('42', '4210', 'Rent income');
        $category = (new RevenueCategories())->save(['category_name' => 'Old rent', 'account_id' => null], null, null);
        $second   = $this->treasury('Restricted drawer');
        $id       = $this->voucher(['treasury_id' => $second, 'category_id' => $category, 'account_id' => $account]);

        (new RevenueCategories())->delete([$category], null);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);
        $this->restrictTreasury($second, 'deposit', [$this->employeeWithGrants([])]);

        $body = $this->get('/receipt_vouchers/view/' . $id)->getBody();

        foreach ([$second, $category, $account] as $kept) {
            $this->assertStringContainsString('<option value="' . $kept . '" selected>', $body, 'Option ' . $kept);
        }

        $this->assertStringNotContainsString('<option value="0"', $this->treasurySelect($body));

        $changed = $this->postJson('/receipt_vouchers/save/' . $id, ['date' => $this->today, 'amount' => '100.00', 'tax_amount' => '0', 'payment_type' => 'Cash', 'treasury_id' => $second, 'category_id' => $category, 'account_id' => $account, 'description' => 'Only the text']);
        $row     = (new RevenueVouchers())->find($id);

        $this->assertTrue($changed['success']);
        $this->assertSame([$second, $category, $account], [(int) $row->treasury_id, (int) $row->revenue_category_id, (int) $row->account_id]);
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'source_id' => $id])->countAllResults(), 'The journal was not reversed and posted again');
    }

    public function testGetView_OffersTheDefaultOptionOnlyToAVoucherWithoutATreasury(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $named = $this->voucher();
        $bare  = (new RevenueVouchers())->save(['date' => $this->today, 'amount' => '100.00', 'payment_type' => 'Cash', 'description' => 'No treasury'], null, $this->clerkId);

        $this->assertStringContainsString('<option value="0">' . lang('Receipt_vouchers.treasury_default') . '</option>', $this->treasurySelect($this->get('/receipt_vouchers/view')->getBody()));
        $this->assertStringNotContainsString('<option value="0"', $this->treasurySelect($this->get('/receipt_vouchers/view/' . $named)->getBody()));
        $this->assertStringContainsString('<option value="0" selected>' . lang('Receipt_vouchers.treasury_none') . '</option>', $this->treasurySelect($this->get('/receipt_vouchers/view/' . $bare)->getBody()));

        $this->assertSame(2, substr_count($this->get('/receipt_vouchers/view/' . $named)->getBody(), '<option value="0"'), 'Only the category and the account have an option for none');
        $this->assertSame(3, substr_count($this->get('/receipt_vouchers/view/' . $bare)->getBody(), '<option value="0"'), 'And the treasury too for a voucher that has none');
    }

    public function testGetView_ListsTheTreasuryOfAVoucherOnceWhenItCanStillBeChosen(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $second = $this->treasury('Second drawer');
        $id     = $this->voucher(['treasury_id' => $second]);
        $body   = $this->get('/receipt_vouchers/view/' . $id)->getBody();

        $this->assertSame(1, substr_count($body, '<option value="' . $second . '"'));
        $this->assertSame(1, substr_count($body, '<option value="' . $second . '" selected>'));
    }

    public function testGetView_ShowsTheAmountAndTheTaxWithEveryDecimalTheyHave(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $precise = $this->voucher(['amount' => '100.125', 'tax_amount' => '5.125']);
        $plain   = $this->voucher(['amount' => '100.00', 'tax_amount' => '15']);

        $body = $this->get('/receipt_vouchers/view/' . $precise)->getBody();

        $this->assertStringContainsString('name="amount" id="amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="100.125"', $body);
        $this->assertStringContainsString('id="tax_amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="5.125"', $body);

        $body = $this->get('/receipt_vouchers/view/' . $plain)->getBody();

        $this->assertStringContainsString('autocomplete="off" value="100.00"', $body);
        $this->assertStringContainsString('autocomplete="off" value="15.00"', $body);

        $saved = $this->postJson('/receipt_vouchers/save/' . $precise, ['date' => $this->today, 'amount' => '100.125', 'tax_amount' => '5.125', 'payment_type' => 'Cash', 'treasury_id' => $this->treasuryId, 'description' => 'Only the text']);

        $this->assertTrue($saved['success']);
        $this->assertSame(['100.1250', '5.1250'], [(new RevenueVouchers())->find($precise)->amount, (new RevenueVouchers())->find($precise)->tax_amount]);
    }

    public function testGetPrint_PrintsTheNumberCategoryTreasuryAmountAndTheAmountInWords(): void
    {
        $this->actAsWith(['receipt_vouchers']);
        (new DocumentNumbering())->configure(DocumentType::Revenue, ['enabled' => true, 'prefix' => 'REC-', 'padding' => 5, 'next_number' => 1]);
        $id = $this->voucher(['amount' => '3000.00']);

        $response = $this->get('/receipt_vouchers/print/' . $id);

        $response->assertStatus(200);
        $response->assertSee(lang('Documents.receipt_voucher'));
        $response->assertSee('REC-00001');
        $response->assertSee('3,000.00 SAR');
        $response->assertSee('Three thousand Saudi Riyals');
        $response->assertSee('Stand rent');
        $response->assertSee('Revenue drawer');
        $response->assertSee(lang('Documents.signature_payer'));
    }

    public function testGetPrint_EscapesMarkupInTheDescription(): void
    {
        $this->actAsWith(['receipt_vouchers']);
        $id = $this->voucher(['description' => '<script>alert(1)</script> rent']);

        $body = $this->get('/receipt_vouchers/print/' . $id)->response()->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; rent', $body);
    }

    public function testGetPrint_ShowsNoVoucherOfADeletedOrUnknownReceipt(): void
    {
        $this->actAsWith(['receipt_vouchers']);
        $id = $this->voucher();
        (new RevenueVouchers())->delete([$id], $this->clerkId);

        $this->expectException(PageNotFoundException::class);

        $this->get('/receipt_vouchers/print/' . $id);
    }

    public function testGetPrint_HidesAVoucherOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $id = $this->voucher();
        $this->actAsWith(['receipt_vouchers'], [$this->createBranch()]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/receipt_vouchers/print/' . $id);
    }

    public function testGetPrint_RedirectsAnEmployeeWithoutTheModuleAndIsNotFoundWhileAccountingIsOff(): void
    {
        $id = $this->voucher();
        $this->actAsWith(['expenses']);

        $this->get('/receipt_vouchers/print/' . $id)->assertRedirect();

        $this->actAsWith(['receipt_vouchers']);
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/receipt_vouchers/print/' . $id);
    }

    public function testGetPdf_ReturnsAPdfWithTheNumberInItsName(): void
    {
        $this->actAsWith(['receipt_vouchers']);
        (new DocumentNumbering())->configure(DocumentType::Revenue, ['enabled' => true, 'prefix' => 'REC-', 'padding' => 5, 'next_number' => 1]);
        $id = $this->voucher();

        $response = $this->get('/receipt_vouchers/pdf/' . $id);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->response()->getBody());
        $this->assertStringContainsString(rawurlencode(lang('Documents.receipt_voucher') . ' REC-00001') . '.pdf', $response->response()->getHeaderLine('Content-Disposition'));
    }

    public function testGetPdf_HidesAVoucherOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $id = $this->voucher();
        $this->actAsWith(['receipt_vouchers'], [$this->createBranch()]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/receipt_vouchers/pdf/' . $id);
    }

    public function testGetPrint_AndPdfChangeNoRowOfTheBooksOrOfTheVouchers(): void
    {
        $this->actAsWith(['receipt_vouchers']);
        $id     = $this->voucher(['amount' => '115.00', 'tax_amount' => '15.00']);
        $before = $this->databaseFingerprint();

        $this->get('/receipt_vouchers/print/' . $id)->assertStatus(200);
        $this->get('/receipt_vouchers/pdf/' . $id)->assertStatus(200);

        $this->assertDatabaseUnchanged($before, 'Printing a voucher changed a row.');
    }

    private function switchMethodsOn(array $changes): void
    {
        (new PaymentMethods())->save($changes, null);
        $this->switchIntegrationOn(IntegrationOptions::PAYMENT_METHODS);
    }

    public function testGetView_ShowsARenamedMethodUnderItsNameAndSendsTheNameItIsStoredUnder(): void
    {
        $this->actAsWith(['receipt_vouchers']);
        $this->switchMethodsOn(['wallet' => ['name' => 'Pay by phone']]);

        $select = $this->selectOf($this->get('/receipt_vouchers/view')->getBody(), 'payment_type');

        $this->assertStringContainsString('<option value="Wallet">Pay by phone</option>', $select);
        $this->assertStringNotContainsString('value="Pay by phone"', $select);
    }

    public function testPostSave_SavesAVoucherReceivedWithARenamedMethodUnderItsStoredName(): void
    {
        $this->actAsWith(['receipt_vouchers']);
        $this->switchMethodsOn(['wallet' => ['name' => 'Pay by phone']]);

        $result = $this->postJson('/receipt_vouchers/save', ['date' => $this->today, 'amount' => '100.00', 'tax_amount' => '0', 'payment_type' => 'Wallet', 'treasury_id' => $this->treasuryId, 'category_id' => 0, 'account_id' => 0, 'description' => 'Renamed way']);

        $this->assertTrue($result['success']);
        $this->assertSame('Wallet', $this->accountingDb()->table('revenues')->where('revenue_id', $result['id'])->get()->getRow()->payment_type);
    }

    public function testGetView_KeepsTheMethodThatWasSwitchedOffSelectedOnItsOwnVoucherOnly(): void
    {
        $id = $this->voucher(['payment_type' => 'Wallet']);
        $this->actAsWith(['receipt_vouchers']);
        $this->switchMethodsOn(['wallet' => ['enabled' => '0']]);

        $edit = $this->selectOf($this->get('/receipt_vouchers/view/' . $id)->getBody(), 'payment_type');
        $new  = $this->selectOf($this->get('/receipt_vouchers/view')->getBody(), 'payment_type');

        $this->assertMatchesRegularExpression('#<option value="Wallet"[^>]*selected#', $edit, 'The voucher keeps the method it was received with.');
        $this->assertStringNotContainsString('value="Wallet"', $new, 'A new voucher is not offered the method that is off.');
    }

    public function testPostSave_SavesAVoucherThatCarriesASwitchedOffMethodWithTheMethodItHad(): void
    {
        $id = $this->voucher(['payment_type' => 'Wallet']);
        $this->actAsWith(['receipt_vouchers']);
        $this->switchMethodsOn(['wallet' => ['enabled' => '0']]);

        $result = $this->postJson('/receipt_vouchers/save/' . $id, ['date' => $this->today, 'amount' => '60.00', 'tax_amount' => '0', 'payment_type' => 'Wallet', 'treasury_id' => $this->treasuryId, 'description' => 'Smaller']);

        $this->assertTrue($result['success']);
        $this->assertSame('Wallet', $this->accountingDb()->table('revenues')->where('revenue_id', $id)->get()->getRow()->payment_type);
    }

    public function testPostSave_RefusesAVoucherChangedToAMethodThatIsSwitchedOffAndANewOne(): void
    {
        $id = $this->voucher();
        $this->actAsWith(['receipt_vouchers']);
        $this->switchMethodsOn(['wallet' => ['enabled' => '0']]);
        $before = $this->accountingDb()->table('revenues')->countAllResults();
        $words  = 'The payment method Wallet is switched off.';

        $changed = $this->postJson('/receipt_vouchers/save/' . $id, ['date' => $this->today, 'amount' => '100.00', 'tax_amount' => '0', 'payment_type' => 'Wallet', 'treasury_id' => $this->treasuryId, 'description' => 'Moved']);
        $made    = $this->postJson('/receipt_vouchers/save', ['date' => $this->today, 'amount' => '100.00', 'tax_amount' => '0', 'payment_type' => 'Wallet', 'treasury_id' => $this->treasuryId]);

        $this->assertSame([false, $words], [$changed['success'], $changed['message']]);
        $this->assertSame([false, $words], [$made['success'], $made['message']]);
        $this->assertSame('Cash', $this->accountingDb()->table('revenues')->where('revenue_id', $id)->get()->getRow()->payment_type);
        $this->assertSame($before, $this->accountingDb()->table('revenues')->countAllResults());
    }
}
