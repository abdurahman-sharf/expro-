<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\Roles\RoleService;
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
 * The screens of sales debit notes: who may open them, what they list for the branches of the
 * employee, that every change is a POST that the books may refuse in words, and that nothing is
 * offered while the setting is off.
 */
class AccountingDebitNotesControllerTest extends CIUnitTestCase
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

    private SalesDebitNotes $notes;
    private int $clerk;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->setConfigValue('accounting_debit_note', '1');

        $this->notes = new SalesDebitNotes();
        $this->clerk = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'sales_debit_note')->delete();
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('app_config')->where('key', 'accounting_debit_note')->delete();
        $this->stopSelling();

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

    private function raise(string $amount = '100', ?int $branchId = null, ?int $employeeId = null): int
    {
        return $this->notes->create(['customer_id' => $this->customerId, 'date' => date('Y-m-d'), 'amount' => $amount, 'branch_id' => $branchId], $employeeId ?? $this->clerk);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    public function testGetIndex_ListsTheNotesAndOffersTheNewOneToWhoMayManageThem(): void
    {
        $id = $this->raise('250');

        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $page = $this->get('/accounting_debit_notes');

        $page->assertStatus(200);
        $page->assertSee('id="debit_note_' . $id . '"');
        $page->assertSee(to_currency('250'));
        $page->assertSee('id="debit_note_new"');
        $page->assertSee(lang('Accounting_debit_notes.reverse'));
    }

    public function testGetIndex_OffersNeitherTheNewNoteNorTakingBackToWhoMayOnlyLook(): void
    {
        $this->raise();
        $this->actAsWith(['accounting_debit_notes']);

        $page = $this->get('/accounting_debit_notes');

        $page->assertStatus(200);
        $page->assertDontSee('id="debit_note_new"');
        $page->assertDontSee('accounting_debit_notes/reverse_form/');
    }

    public function testGetIndex_RedirectsAnEmployeeWithoutTheModuleGrant(): void
    {
        $this->actAsWith([]);

        $page = $this->get('/accounting_debit_notes');

        $this->assertNotSame(200, $page->response()->getStatusCode());
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAsWith(['accounting_debit_notes']);
        $this->setConfigValue('accounting_enabled', '0');
        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_debit_notes');
    }

    public function testGetIndex_SaysTheSettingIsOffAndOffersNoNewNoteWhileItIs(): void
    {
        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);
        $this->setConfigValue('accounting_debit_note', '0');

        $page = $this->get('/accounting_debit_notes');

        $page->assertSee('id="debit_notes_off"');
        $page->assertDontSee('id="debit_note_new"');
    }

    public function testGetIndex_ShowsOnlyTheNotesOfTheBranchesOfTheEmployeeAndSaysSo(): void
    {
        $other = $this->createBranch();
        $mine  = $this->raise('111');
        $theirs = $this->raise('222', $other, $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE], [$other]));

        $this->actAsWith(['accounting_debit_notes'], [$other]);

        $page = $this->get('/accounting_debit_notes');

        $page->assertSee('id="debit_note_' . $theirs . '"');
        $page->assertDontSee('id="debit_note_' . $mine . '"');
        $page->assertSee('id="debit_notes_scope"');
        $page->assertSee(lang('Accounting_debit_notes.scope_notice'));
    }

    public function testGetIndex_SaysNothingAboutTheScopeToAnEmployeeOfEveryBranch(): void
    {
        $this->raise('111');

        $this->actAsWith(['accounting_debit_notes']);

        $page = $this->get('/accounting_debit_notes');

        $page->assertDontSee('id="debit_notes_scope"');
        $page->assertDontSee(lang('Accounting_debit_notes.scope_notice'));
    }

    public function testGetIndex_PagesTheNotesWithLinksToTheNextAndThePreviousPage(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->raise('10');
        }

        $this->actAsWith(['accounting_debit_notes']);

        $first = $this->get('/accounting_debit_notes');

        $first->assertSee(lang('Accounting_debit_notes.next'));
        $first->assertSee('offset=25');
        $first->assertDontSee(lang('Accounting_debit_notes.previous'));
        $this->assertSame(25, preg_match_all('/id="debit_note_\d+"/', $first->getBody()));

        $second = $this->get('/accounting_debit_notes?offset=25');

        $second->assertSee(lang('Accounting_debit_notes.previous'));
        $this->assertMatchesRegularExpression('/href="[^"?]*accounting_debit_notes">' . preg_quote(lang('Accounting_debit_notes.previous'), '/') . '</', $second->getBody(), 'the first page needs no offset');
        $second->assertDontSee(lang('Accounting_debit_notes.next'));
        $this->assertSame(1, preg_match_all('/id="debit_note_\d+"/', $second->getBody()));
    }

    public function testGetIndex_FiltersByStatusAndKeepsTheReasonOfATakenBackNote(): void
    {
        $posted   = $this->raise('100');
        $reversed = $this->raise('200');

        $this->notes->reverse($reversed, 'duplicate of another', $this->clerk);
        $this->actAsWith(['accounting_debit_notes']);

        $page = $this->get('/accounting_debit_notes?status=reversed');

        $page->assertSee('id="debit_note_' . $reversed . '"');
        $page->assertSee('duplicate of another');
        $page->assertDontSee('id="debit_note_' . $posted . '"');

        $this->get('/accounting_debit_notes?status=posted')->assertDontSee('id="debit_note_' . $reversed . '"');
    }

    public function testGetForm_ServesTheFormToWhoMayManageNotesAndRefusesEveryoneElse(): void
    {
        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $form = $this->get('/accounting_debit_notes/form');

        $form->assertStatus(200);
        $form->assertSee('name="amount"');
        $form->assertSee('name="tax_percent"');
        $form->assertSee('name="account_id"');

        $this->actAsWith(['accounting_debit_notes']);

        $this->assertSame(403, $this->get('/accounting_debit_notes/form')->response()->getStatusCode());
    }

    public function testGetForm_ValidatesTheVisibleCustomerFieldBecauseTheHiddenOneIsNeverChecked(): void
    {
        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $body = $this->get('/accounting_debit_notes/form')->getBody();

        $this->assertStringContainsString("customer_name: 'required'", $body);
        $this->assertStringNotContainsString("customer_id: 'required'", $body);
        $this->assertStringContainsString('focus: function(event, ui)', $body, 'moving through the suggestions shows the name and not the number');
        $this->assertStringContainsString("if ($('#customer_id').val() === '')", $body, 'a name that was typed and not chosen is emptied');
    }

    public function testGetCustomers_FindsCustomersByNameForWhoMayManageNotesAndNoOneElse(): void
    {
        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $found = json_decode($this->get('/accounting_debit_notes/customers?term=Omar')->getJSON(), true);

        $this->assertContains($this->customerId, array_column($found, 'value'));
        $this->assertSame([], json_decode($this->get('/accounting_debit_notes/customers?term=zzzzzz-nobody')->getJSON(), true));

        $this->actAsWith(['accounting_debit_notes']);

        $this->assertSame(403, $this->get('/accounting_debit_notes/customers')->response()->getStatusCode());
    }

    public function testPostSave_RaisesTheNoteAndTheCustomerOwesIt(): void
    {
        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $answer = $this->postJson('/accounting_debit_notes/save', ['customer_id' => (string) $this->customerId, 'note_date' => date('Y-m-d'), 'amount' => '100', 'tax_percent' => '15', 'account_id' => '', 'note' => 'freight']);

        $this->assertTrue($answer['success']);
        $this->assertSame(lang('Accounting_debit_notes.successful_adding'), $answer['message']);
        $this->assertSame('115.00', $this->balance($this->customerAccount()));
        $this->assertSame('freight', $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $answer['id'])->get()->getRow()->note);
    }

    public function testPostSave_SaysInWordsWhyTheBooksRefuseAndSavesNothing(): void
    {
        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $answer = $this->postJson('/accounting_debit_notes/save', ['customer_id' => (string) $this->customerId, 'note_date' => date('Y-m-d'), 'amount' => '0']);

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_debit_note_amount_invalid'), $answer['message']);
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
    }

    public function testPostSave_SaysInWordsWhyAnAmountOfLessThanHalfACentIsRefusedInsteadOfFailing(): void
    {
        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $answer = $this->postJson('/accounting_debit_notes/save', ['customer_id' => (string) $this->customerId, 'note_date' => date('Y-m-d'), 'amount' => '0.004', 'tax_percent' => '0']);

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_debit_note_amount_invalid'), $answer['message']);
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
    }

    public function testPostSave_RefusesAnEmployeeWithoutTheManagePermissionEvenIfTheyHoldTheModule(): void
    {
        $this->actAsWith(['accounting_debit_notes']);

        $answer = $this->postJson('/accounting_debit_notes/save', ['customer_id' => (string) $this->customerId, 'note_date' => date('Y-m-d'), 'amount' => '100']);

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $answer['message']);
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
    }

    public function testPostSave_RefusesWhileTheSettingIsOff(): void
    {
        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);
        $this->setConfigValue('accounting_debit_note', '0');

        $answer = $this->postJson('/accounting_debit_notes/save', ['customer_id' => (string) $this->customerId, 'note_date' => date('Y-m-d'), 'amount' => '100']);

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_debit_note_off'), $answer['message']);
    }

    public function testGetReverseForm_ServesTheFormToWhoMayManageTheNotesOfTheirBranchesOnly(): void
    {
        $id = $this->raise();

        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $this->get('/accounting_debit_notes/reverse_form/' . $id)->assertSee('name="reason"');

        $other = $this->createBranch();

        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE], [$other]);

        $this->assertSame(403, $this->get('/accounting_debit_notes/reverse_form/' . $id)->response()->getStatusCode());
        $this->assertSame(403, $this->get('/accounting_debit_notes/reverse_form/99999999')->response()->getStatusCode());
    }

    public function testPostReverse_TakesTheNoteBackWithAReasonAndRefusesItInWordsWithout(): void
    {
        $id = $this->raise();

        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $refused = $this->postJson('/accounting_debit_notes/reverse/' . $id, ['reason' => '']);

        $this->assertFalse($refused['success']);
        $this->assertSame('100.00', $this->balance($this->customerAccount()));

        $done = $this->postJson('/accounting_debit_notes/reverse/' . $id, ['reason' => 'mistake']);

        $this->assertTrue($done['success']);
        $this->assertSame(lang('Accounting_debit_notes.successful_reversing'), $done['message']);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));

        $again = $this->postJson('/accounting_debit_notes/reverse/' . $id, ['reason' => 'again']);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_debit_note_reversed'), $again['message']);
    }

    public function testPostReverse_RefusesAnEmployeeOfAnotherBranchAndOneWithoutThePermission(): void
    {
        $id    = $this->raise();
        $other = $this->createBranch();

        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE], [$other]);

        $this->assertFalse($this->postJson('/accounting_debit_notes/reverse/' . $id, ['reason' => 'wrong branch'])['success']);

        $this->actAsWith(['accounting_debit_notes']);

        $this->assertSame(lang('Accounting.error_permission_denied'), $this->postJson('/accounting_debit_notes/reverse/' . $id, ['reason' => 'no grant'])['message']);
        $this->assertSame('posted', $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $id)->get()->getRow()->status);
    }

    public function testCustomerPosition_ShowsTheNoteAsAnOpenItemWithItsNumberAndTheCreditSpentOnIt(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');

        $id = $this->raise('100');

        (new CustomerOpening())->create($this->customerId, CustomerOpening::WE_OWE, '40', date('Y-m-d', strtotime('-30 days')), 'credit', $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE]));

        $this->actAsWith(['accounting_receivables']);

        $page = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $page->assertStatus(200);
        $page->assertSee(lang('Accounting_receivables.source_debit_note'));
        $page->assertSee('#' . str_pad((string) $id, 5, '0', STR_PAD_LEFT));
        $this->accountingDb()->table('app_config')->where('key', 'accounting_customer_opening')->delete();
    }

    public function testRoutes_AcceptNoChangeByGet(): void
    {
        $id = $this->raise();

        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        foreach (['/accounting_debit_notes/reverse/' . $id, '/accounting_debit_notes/save'] as $url) {
            try {
                $this->get($url);
                $this->fail('A GET must not reach ' . $url);
            } catch (PageNotFoundException) {
                $this->assertSame('posted', $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $id)->get()->getRow()->status);
            }
        }
    }

    private function actAsOwnRecordsEmployee(): int
    {
        $personId = $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        (new RoleService())->setRecordScope($personId, 'own', null);

        return $personId;
    }

    public function testGetIndex_ListsOnlyTheNotesTheEmployeeRaisedWhenTheEmployeeSeesTheirOwnRecords(): void
    {
        $theirs = $this->raise('222');
        $viewer = $this->actAsOwnRecordsEmployee();
        $mine   = $this->raise('111', null, $viewer);

        $page = $this->get('/accounting_debit_notes');

        $page->assertSee('id="debit_note_' . $mine . '"');
        $page->assertDontSee('id="debit_note_' . $theirs . '"');
    }

    public function testGetIndex_ListsEveryNoteToAnEmployeeWhoSeesEveryRecord(): void
    {
        $first = $this->raise('222');

        $this->actAsWith(['accounting_debit_notes', AccountingGuard::DEBIT_NOTES_MANAGE]);

        $second = $this->raise('111');
        $page   = $this->get('/accounting_debit_notes');

        $page->assertSee('id="debit_note_' . $first . '"');
        $page->assertSee('id="debit_note_' . $second . '"');
    }

    public function testGetReverseForm_RefusesAnotherEmployeesNoteWhenTheEmployeeSeesTheirOwnRecords(): void
    {
        $theirs = $this->raise();
        $viewer = $this->actAsOwnRecordsEmployee();
        $mine   = $this->raise('50', null, $viewer);

        $this->assertSame(403, $this->get('/accounting_debit_notes/reverse_form/' . $theirs)->response()->getStatusCode());
        $this->get('/accounting_debit_notes/reverse_form/' . $mine)->assertSee('name="reason"');
    }

    public function testPostReverse_RefusesAnotherEmployeesNoteInWordsWhenTheEmployeeSeesTheirOwnRecords(): void
    {
        $theirs = $this->raise();

        $this->actAsOwnRecordsEmployee();

        $refused = $this->postJson('/accounting_debit_notes/reverse/' . $theirs, ['reason' => 'by a stranger']);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_debit_note_not_found'), $refused['message']);
        $this->assertSame('posted', $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $theirs)->get()->getRow()->status);
        $this->assertSame('100.00', $this->balance($this->customerAccount()));
    }
}
