<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ChequeBooks;
use App\Libraries\Accounting\ChequeService;
use App\Libraries\Accounting\ChequeSources;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
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
 * The screens of cheques: who may open them, what they list and show, that every change of a
 * cheque is a POST that the books may refuse in words, the cheque books and the two reports.
 */
class AccountingChequesControllerTest extends CIUnitTestCase
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

    private ChequeService $cheques;
    private int $clerk;
    private int $bank;
    private int $book;
    private string $today;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->cheques = new ChequeService();
        $this->clerk   = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
        $this->bank    = (new TreasuryService())->create(['name' => 'Bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => 0], $this->clerk);
        $this->book    = (new ChequeBooks())->create(['treasury_id' => $this->bank, 'name' => 'Book 1', 'first_serial' => '800001', 'last_serial' => '800050'], $this->clerk);
        $this->today   = date('Y-m-d');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopBuying();

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

    private function manager(?array $branchIds = null): int
    {
        return $this->actAsWith(['accounting_cheques', AccountingGuard::CHEQUES_MANAGE], $branchIds);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function received(array $changes = []): int
    {
        return $this->cheques->receive([...['party_id' => $this->customerId, 'treasury_id' => $this->bank, 'cheque_number' => '710001', 'amount' => '100.00', 'issue_date' => $this->today, 'due_date' => $this->today], ...$changes], $this->clerk);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function paid(array $changes = [], ?string $deliverOn = null): int
    {
        return $this->cheques->issue([...['party_id' => $this->supplierId, 'book_id' => $this->book, 'cheque_number' => '800001', 'amount' => '200.00', 'issue_date' => $this->today, 'due_date' => $this->today], ...$changes], $this->clerk, $deliverOn);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    private function statusOf(int $chequeId): string
    {
        return (string) $this->accountingDb()->table('cheques')->where('cheque_id', $chequeId)->get()->getRow()->status;
    }

    public function testGetIndex_ShowsTheListAndOffersTheNewChequeToWhoMayChangeCheques(): void
    {
        $this->actAsWith(['accounting_cheques']);

        $view = $this->get('/accounting_cheques');

        $view->assertStatus(200);
        $view->assertSee(lang('Accounting_cheques.direction_received'));
        $view->assertDontSee(lang('Accounting_cheques.new_received'));

        $this->manager();

        $this->get('/accounting_cheques')->assertSee(lang('Accounting_cheques.new_received'));
        $this->get('/accounting_cheques?direction=paid')->assertSee(lang('Accounting_cheques.new_paid'));
        $this->get('/accounting_cheques?direction=paid')->assertSee(lang('Accounting_cheques.books'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAsWith(['accounting_payables']);

        $this->get('/accounting_cheques')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAsWith(['accounting_cheques']);
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_cheques');
    }

    public function testGetSearch_ListsTheChequesOfTheDirectionWithTheirFiltersAndTheBranchesOfTheReader(): void
    {
        $this->manager();

        $branch   = $this->createBranch();
        $bank     = (new TreasuryService())->create(['name' => 'Branch bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => $branch], $this->clerk);
        $first    = $this->received();
        $second   = $this->received(['cheque_number' => '710002', 'treasury_id' => $bank]);
        $paid     = $this->paid();
        $this->cheques->deposit($first, $this->today, $this->clerk);

        $ids = fn (string $query): array => array_map(static fn (array $row): int => (int) $row['cheque_id'], json_decode($this->get('/accounting_cheques/search' . $query)->getJSON(), true)['rows']);

        $this->assertSame([$second, $first], $ids('?direction=received'));
        $this->assertSame([$paid], $ids('?direction=paid'));
        $this->assertSame([$first], $ids('?direction=received&status=deposited'));
        $this->assertSame([$second], $ids('?direction=received&search=710002'));
        $this->assertSame([], $ids('?direction=received&date_from=' . date('Y-m-d', strtotime('+1 day'))));

        $this->manager([1]);

        $this->assertSame([$first], $ids('?direction=received'));
    }

    public function testGetSearch_ShowsTheStatusTheDatesAndTheActionsThatTheStatusAllows(): void
    {
        $this->manager();

        $received = $this->received();
        $row      = json_decode($this->get('/accounting_cheques/search?direction=received')->getJSON(), true)['rows'][0];

        $this->assertSame($received, (int) $row['cheque_id']);
        $this->assertStringContainsString(lang('Accounting_cheques.status_received'), $row['status']);
        $this->assertStringContainsString('accounting_cheques/action_form/deposit/' . $received, $row['edit']);
        $this->assertStringContainsString('accounting_cheques/action_form/collect/' . $received, $row['edit']);
        $this->assertStringContainsString('data-acc-action="delete"', $row['edit']);

        $this->cheques->deposit($received, $this->today, $this->clerk);

        $row = json_decode($this->get('/accounting_cheques/row/' . $received)->getJSON(), true);

        $this->assertStringNotContainsString('action_form/deposit', $row['edit']);
        $this->assertStringNotContainsString('data-acc-action="delete"', $row['edit']);
        $this->assertStringContainsString('data-acc-action="undo"', $row['edit']);

        $this->actAsWith(['accounting_cheques']);

        $this->assertStringNotContainsString('action_form', json_decode($this->get('/accounting_cheques/row/' . $received)->getJSON(), true)['edit']);
    }

    public function testGetDetail_ShowsTheHistoryAndTheJournalsOfTheCheque(): void
    {
        $this->actAsWith(['accounting_cheques', 'accounting_journals']);

        $chequeId = $this->received();

        $this->cheques->collect($chequeId, $this->today, null, $this->clerk);
        $this->cheques->undo($chequeId, $this->clerk);

        $view = $this->get('/accounting_cheques/view/' . $chequeId);

        $view->assertStatus(200);
        $view->assertSee('710001');
        $view->assertSee(lang('Accounting.source_' . ChequeSources::RECEIVED));
        $view->assertSee(lang('Accounting.source_' . ChequeSources::COLLECTED));
        $view->assertSee(lang('Accounting_cheques.undone'));
        $view->assertSee('accounting_journals/view/');
    }

    public function testGetDetail_IsNotFoundForAChequeOfAnotherBranchOrADeletedOne(): void
    {
        $chequeId = $this->received();
        $other    = $this->createBranch();

        $this->actAsWith(['accounting_cheques'], [$other]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_cheques/view/' . $chequeId);
    }

    public function testGetDetail_IsNotFoundForADeletedCheque(): void
    {
        $chequeId = $this->received();

        $this->cheques->delete($chequeId, $this->clerk);
        $this->actAsWith(['accounting_cheques']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_cheques/view/' . $chequeId);
    }

    public function testGetForm_ShowsTheFieldsOfAReceivedChequeAndOfAPaidOne(): void
    {
        $this->manager();

        $received = $this->get('/accounting_cheques/form/received');

        $received->assertStatus(200);
        $received->assertSee(lang('Accounting_cheques.collection_bank'));
        $received->assertSee('name="cheque_number"');
        $received->assertDontSee('name="book_id"');

        $paid = $this->get('/accounting_cheques/form/paid');

        $paid->assertSee('name="book_id"');
        $paid->assertSee(lang('Accounting_cheques.issue_and_deliver'));
        $paid->assertDontSee('name="treasury_id"');
    }

    public function testGetForm_LocksTheFinancialFieldsOfAChequeThatHasMovedOn(): void
    {
        $this->manager();

        $chequeId = $this->received();

        $this->get('/accounting_cheques/form/received/' . $chequeId)->assertDontSee(lang('Accounting_cheques.locked_note'));

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $locked = $this->get('/accounting_cheques/form/received/' . $chequeId);

        $locked->assertSee(lang('Accounting_cheques.locked_note'));
        $locked->assertSee('readonly');
    }

    public function testGetForm_IsNotFoundWithoutThePermissionToChangeCheques(): void
    {
        $this->actAsWith(['accounting_cheques']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_cheques/form/received');
    }

    public function testPostSaveCheque_ReceivesAChequeAndEditsIt(): void
    {
        $this->manager();

        $added = $this->postJson('/accounting_cheques/save/received', ['party_id' => $this->customerId, 'treasury_id' => $this->bank, 'cheque_number' => '710001', 'amount' => '100.00', 'issue_date' => $this->today, 'due_date' => $this->today, 'drawer_name' => 'Omar']);

        $this->assertTrue($added['success']);
        $this->assertSame(lang('Accounting_cheques.successful_adding'), $added['message']);
        $this->assertSame('received', $this->statusOf((int) $added['id']));

        $changed = $this->postJson('/accounting_cheques/save/received/' . $added['id'], ['party_id' => $this->customerId, 'treasury_id' => $this->bank, 'cheque_number' => '710001', 'amount' => '120.00', 'issue_date' => $this->today, 'due_date' => $this->today]);

        $this->assertTrue($changed['success']);
        $this->assertSame('120.0000', $this->accountingDb()->table('cheques')->where('cheque_id', $added['id'])->get()->getRow()->amount);
    }

    public function testPostSaveCheque_IssuesAndDeliversAChequeToASupplierWhenAsked(): void
    {
        $this->manager();

        $issued = $this->postJson('/accounting_cheques/save/paid', ['party_id' => $this->supplierId, 'book_id' => $this->book, 'cheque_number' => '800001', 'amount' => '50.00', 'issue_date' => $this->today, 'due_date' => $this->today]);
        $both   = $this->postJson('/accounting_cheques/save/paid', ['party_id' => $this->supplierId, 'book_id' => $this->book, 'cheque_number' => '800002', 'amount' => '50.00', 'issue_date' => $this->today, 'due_date' => $this->today, 'deliver' => '1']);

        $this->assertSame(['issued', 'delivered'], [$this->statusOf((int) $issued['id']), $this->statusOf((int) $both['id'])]);
    }

    public function testPostSaveCheque_SaysInWordsWhatTheBooksRefuse(): void
    {
        $this->manager();

        $bad = $this->postJson('/accounting_cheques/save/received', ['party_id' => $this->customerId, 'treasury_id' => $this->bank, 'cheque_number' => '710001', 'amount' => '0', 'issue_date' => $this->today]);

        $this->assertFalse($bad['success']);
        $this->assertSame(lang('Accounting.error_cheque_amount_invalid'), $bad['message']);
        $this->assertSame(0, $this->countRows('cheques'));

        $this->closeThrough($this->today);

        $closed = $this->postJson('/accounting_cheques/save/received', ['party_id' => $this->customerId, 'treasury_id' => $this->bank, 'cheque_number' => '710001', 'amount' => '10.00', 'issue_date' => $this->today]);

        $this->assertFalse($closed['success']);
        $this->assertNotSame('', $closed['message']);
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testPostSaveCheque_RefusesAnEmployeeWithoutThePermissionToChangeCheques(): void
    {
        $this->actAsWith(['accounting_cheques']);

        $denied = $this->postJson('/accounting_cheques/save/received', ['party_id' => $this->customerId, 'treasury_id' => $this->bank, 'cheque_number' => '710001', 'amount' => '10.00', 'issue_date' => $this->today]);

        $this->assertFalse($denied['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $denied['message']);
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testPostAct_DepositsCollectsRejectsAndDelivers(): void
    {
        $this->manager();

        $received = $this->received();
        $second   = $this->received(['cheque_number' => '710002']);
        $paid     = $this->paid();

        $this->assertTrue($this->postJson('/accounting_cheques/act/deposit/' . $received, ['date' => $this->today])['success']);
        $this->assertSame('deposited', $this->statusOf($received));

        $this->assertTrue($this->postJson('/accounting_cheques/act/collect/' . $received, ['date' => $this->today, 'fees' => '1.50', 'note' => 'Bank fee'])['success']);
        $this->assertSame('collected', $this->statusOf($received));

        $this->assertTrue($this->postJson('/accounting_cheques/act/reject/' . $second, ['date' => $this->today, 'note' => 'No funds'])['success']);
        $this->assertSame('rejected', $this->statusOf($second));

        $this->assertTrue($this->postJson('/accounting_cheques/act/deliver/' . $paid, ['date' => $this->today])['success']);
        $this->assertSame('delivered', $this->statusOf($paid));
    }

    public function testPostAct_SaysInWordsWhyAnActionIsRefused(): void
    {
        $this->manager();

        $chequeId = $this->received(['due_date' => date('Y-m-d', strtotime('+30 days'))]);
        $refused  = $this->postJson('/accounting_cheques/act/collect/' . $chequeId, ['date' => $this->today]);

        $this->assertFalse($refused['success']);
        $this->assertStringContainsString(date('Y-m-d', strtotime('+30 days')), $refused['message']);
        $this->assertSame('received', $this->statusOf($chequeId));

        $unknown = $this->postJson('/accounting_cheques/act/launder/' . $chequeId, ['date' => $this->today]);

        $this->assertFalse($unknown['success']);
    }

    public function testPostAct_NeverChangesAChequeThroughALink(): void
    {
        $this->manager();

        $chequeId = $this->received();

        foreach (['act/deposit', 'undo', 'cancel', 'delete'] as $path) {
            try {
                $this->get('/accounting_cheques/' . $path . '/' . $chequeId);
                $this->fail('A GET changed the status of a cheque: ' . $path);
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('received', $this->statusOf($chequeId));
    }

    public function testPostAct_RefusesAChequeOfAnotherBranch(): void
    {
        $chequeId = $this->received();
        $other    = $this->createBranch();

        $this->manager([$other]);

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_cheques/act/deposit/' . $chequeId, ['date' => $this->today]);
    }

    public function testGetActionForm_AsksForTheFeesOnlyWhenADepositedChequeIsCollected(): void
    {
        $this->manager();

        $chequeId = $this->received();

        $this->get('/accounting_cheques/action_form/collect/' . $chequeId)->assertDontSee('name="fees"');

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $this->get('/accounting_cheques/action_form/collect/' . $chequeId)->assertSee('name="fees"');
    }

    public function testGetActionForm_IsNotFoundForAnActionTheStatusDoesNotAllow(): void
    {
        $this->manager();

        $chequeId = $this->received();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_cheques/action_form/deliver/' . $chequeId);
    }

    public function testPostUndoPostCancelPostDelete_ChangeTheChequeAsTheServiceDoes(): void
    {
        $this->manager();

        $received = $this->received();
        $paid     = $this->paid();

        $this->cheques->collect($received, $this->today, null, $this->clerk);

        $this->assertTrue($this->postJson('/accounting_cheques/undo/' . $received)['success']);
        $this->assertSame('received', $this->statusOf($received));

        $this->assertTrue($this->postJson('/accounting_cheques/cancel/' . $paid)['success']);
        $this->assertSame('cancelled', $this->statusOf($paid));

        $this->assertTrue($this->postJson('/accounting_cheques/delete/' . $received)['success']);
        $this->assertSame('1', (string) $this->accountingDb()->table('cheques')->where('cheque_id', $received)->get()->getRow()->deleted);

        $refused = $this->postJson('/accounting_cheques/delete/' . $paid);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_cheque_delete_locked'), $refused['message']);
    }

    public function testBooks_ListAddChangeAndDeleteTheChequeBooks(): void
    {
        $this->manager();

        $this->get('/accounting_cheques/books')->assertSee('Book 1');
        $this->get('/accounting_cheques/book_form')->assertSee('name="first_serial"');

        $added = $this->postJson('/accounting_cheques/save_book', ['treasury_id' => $this->bank, 'name' => 'Book 2', 'first_serial' => '900001', 'last_serial' => '900010']);

        $this->assertTrue($added['success']);

        $this->get('/accounting_cheques/books')->assertSee('Book 2');
        $this->get('/accounting_cheques/book_form/' . $added['id'])->assertSee('900001');

        $this->assertTrue($this->postJson('/accounting_cheques/save_book/' . $added['id'], ['name' => 'Book two', 'first_serial' => '900001', 'last_serial' => '900020'])['success']);
        $this->assertTrue($this->postJson('/accounting_cheques/book_active/' . $added['id'] . '/0')['success']);
        $this->assertSame('0', (string) $this->accountingDb()->table('cheque_books')->where('book_id', $added['id'])->get()->getRow()->active);
        $this->assertTrue($this->postJson('/accounting_cheques/delete_book/' . $added['id'])['success']);

        $this->paid();

        $refused = $this->postJson('/accounting_cheques/delete_book/' . $this->book);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_cheque_book_in_use'), $refused['message']);
    }

    public function testBooks_AreHiddenAndUnchangeableForAnotherBranch(): void
    {
        $other = $this->createBranch();

        $this->manager([$other]);

        $this->get('/accounting_cheques/books')->assertDontSee('Book 1');

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_cheques/save_book/' . $this->book, ['name' => 'x', 'first_serial' => '1', 'last_serial' => '2']);
    }

    public function testGetReport_ListsTheChequesWithTheTotalsByStatusAndTiesToTheAccounts(): void
    {
        $this->actAsWith(['accounting_cheques']);

        $this->received();

        $second = $this->received(['cheque_number' => '710002', 'amount' => '40.00']);
        $third  = $this->received(['cheque_number' => '710003', 'amount' => '10.00']);

        $this->cheques->deposit($second, $this->today, $this->clerk);
        $this->cheques->collect($third, $this->today, null, $this->clerk);

        $view = $this->get('/accounting_cheques/report/received');

        $view->assertStatus(200);
        $view->assertSee('710001');
        $view->assertSee(lang('Accounting_cheques.tie_agrees'));
        $view->assertDontSee(lang('Accounting_cheques.tie_differs'));

        $body = $view->getBody();

        $this->assertMatchesRegularExpression('#id="total_received"[^>]*>.*100\.00#s', $body);
        $this->assertMatchesRegularExpression('#id="total_deposited"[^>]*>.*40\.00#s', $body);
        $this->assertMatchesRegularExpression('#id="total_all"[^>]*>.*150\.00#s', $body);
        $this->assertSame(1, substr_count($body, 'id="cheque_report"'), 'The report table is there once');

        $filtered = $this->get('/accounting_cheques/report/received?status=collected')->getBody();

        $this->assertStringContainsString('710003', $filtered);
        $this->assertStringNotContainsString('710001', $filtered);
    }

    public function testGetReport_OfThePaidChequesTiesToNotesPayable(): void
    {
        $this->actAsWith(['accounting_cheques']);

        $this->paid([], $this->today);
        $this->paid(['cheque_number' => '800002', 'amount' => '30.00']);

        $view = $this->get('/accounting_cheques/report/paid');

        $view->assertSee('800001');
        $view->assertSee(lang('Accounting_cheques.tie_agrees'));
        $this->assertMatchesRegularExpression('#id="total_delivered"[^>]*>.*200\.00#s', $view->getBody());
        $this->assertMatchesRegularExpression('#id="total_issued"[^>]*>.*30\.00#s', $view->getBody());
    }

    public function testGetReport_ShowsOnlyTheChequesAndTheTiesOfTheBranchesOfTheReader(): void
    {
        $branch = $this->createBranch();
        $bank   = (new TreasuryService())->create(['name' => 'Branch bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => $branch], $this->clerk);

        $this->received();
        $this->received(['cheque_number' => '710002', 'treasury_id' => $bank, 'amount' => '70.00']);

        $this->actAsWith(['accounting_cheques'], [1]);

        $body = $this->get('/accounting_cheques/report/received')->getBody();

        $this->assertStringContainsString('710001', $body);
        $this->assertStringNotContainsString('710002', $body);
        $this->assertStringContainsString(lang('Accounting_cheques.tie_agrees'), $body);
        $this->assertStringNotContainsString(lang('Accounting_cheques.tie_differs'), $body);
    }

    public function testGetReport_SaysTheAccountsDifferWhenAJournalByHandMovedWhatTheChequesHold(): void
    {
        $this->actAsWith(['accounting_cheques']);

        $this->received();

        (new PostingService())->post(new JournalDraft(
            date: $this->today,
            lines: [new JournalLineDraft($this->accountId('notes_receivable'), debit: '5.00'), new JournalLineDraft($this->accountId('cash'), credit: '5.00')],
            sourceType: 'manual',
        ));

        $view = $this->get('/accounting_cheques/report/received');

        $view->assertSee(lang('Accounting_cheques.tie_differs'));
    }

    public function testGetActionFormAndGetBookForm_AreNotFoundWithoutThePermissionToChangeCheques(): void
    {
        $chequeId = $this->received();

        $this->actAsWith(['accounting_cheques']);

        foreach (['/accounting_cheques/action_form/deposit/' . $chequeId, '/accounting_cheques/book_form'] as $path) {
            try {
                $this->get($path);
                $this->fail('A person who cannot change cheques opened ' . $path);
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGetForm_IsNotFoundForAChequeOfTheOtherDirection(): void
    {
        $this->manager();

        $chequeId = $this->received();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_cheques/form/paid/' . $chequeId);
    }

    public function testGetIndex_ListsOnlyTheStatusesOfItsDirection(): void
    {
        $this->actAsWith(['accounting_cheques']);

        $received = $this->get('/accounting_cheques?direction=received')->getBody();
        $paid     = $this->get('/accounting_cheques?direction=paid')->getBody();

        $this->assertStringContainsString('value="deposited"', $received);
        $this->assertStringNotContainsString('value="delivered"', $received);
        $this->assertStringContainsString('value="delivered"', $paid);
        $this->assertStringNotContainsString('value="deposited"', $paid);
        $this->assertStringContainsString('value="rejected"', $paid);
    }
}
