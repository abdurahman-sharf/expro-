<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Backfill\BackfillStatus;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Filters;
use Config\Services;
use ReflectionClass;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of the retroactive posting: who may open it, what a batch shows before it is approved, and
 * that the forms that approve and cancel ask for the token against forged requests and for a confirmation.
 *
 * @internal
 */
final class AccountingBackfillControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
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

        $this->startBackfill();
        $this->actAsManager();
    }

    protected function tearDown(): void
    {
        $this->stopBackfill();

        parent::tearDown();
    }

    private function actAsManager(): void
    {
        $this->withSession(['person_id' => $this->backfillManager, 'menu_group' => 'home']);
        $this->accountingDb()->table('grants')->ignore(true)->insert(['permission_id' => 'accounting_periods', 'person_id' => $this->backfillManager, 'menu_group' => 'home']);
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function actAs(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_periods', ...$permissions], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/backfill');
    }

    public function testGetIndex_ShowsTheNoteAndNoBatchToAUserWithoutThePermission(): void
    {
        $this->legacyExpense();
        $this->previewed();
        $this->actAs([]);

        $response = $this->get('/accounting_periods/backfill');

        $response->assertStatus(200);
        $response->assertSee('backfill_permission_note');
        $response->assertDontSee('backfill_batches');
        $response->assertDontSee('backfill_new');
    }

    public function testGetIndex_ListsTheBatchesWithTheirFigures(): void
    {
        $this->legacyExpense();
        $this->legacyInvoice();

        $batch = $this->previewed(['name' => 'March backlog']);

        $response = $this->get('/accounting_periods/backfill');

        $response->assertStatus(200);
        $response->assertSee('March backlog');
        $response->assertSee('href="' . base_url('accounting_periods/backfill/view/' . $batch->id) . '"');
        $response->assertSee(lang('Accounting_backfill.status_preview'));
        $response->assertSee('backfill_new');
    }

    public function testGetIndex_SaysWhatStandsInTheWayOfApproving(): void
    {
        $this->setConfigValue('accounting_backfill_enabled', '0');

        $response = $this->get('/accounting_periods/backfill');

        $response->assertSee('backfill_blockers');
        $response->assertSee(lang('Accounting_backfill.blocker_backfill_off'));
    }

    public function testGetIndex_HidesAnotherBranchesBatchFromAUserWhoWorksInOnlyOneBranch(): void
    {
        $other = $this->createBranch('B2', 'Second branch');

        $this->legacyExpense('100', '0', '2026-03-12', $other);

        $batch = $this->previewed(['name' => 'Branch two', 'branch_id' => $other]);

        $this->actAs([AccountingGuard::BACKFILL_MANAGE], [1]);

        $response = $this->get('/accounting_periods/backfill');

        $response->assertDontSee('Branch two');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/backfill/view/' . $batch->id);
    }

    public function testGetNewForm_IsNotFoundWithoutThePermissionAndOffersTheCompanyOnlyToWhoWorksEverywhere(): void
    {
        $this->actAs([], [1]);

        try {
            $this->get('/accounting_periods/backfill/new_form');
            $this->fail('The form opened without the permission.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->actAs([AccountingGuard::BACKFILL_MANAGE], [1]);
        $this->get('/accounting_periods/backfill/new_form')->assertDontSee(lang('Accounting_backfill.scope_company'));

        $this->actAs([AccountingGuard::BACKFILL_MANAGE]);
        $this->get('/accounting_periods/backfill/new_form')->assertSee(lang('Accounting_backfill.scope_company'));
    }

    public function testPostCreate_MakesTheBatchAndSaysWhereToGo(): void
    {
        $result = $this->json($this->post('/accounting_periods/backfill/create', ['name' => 'From the screen', 'from' => '2026-01-01', 'to' => '2026-12-31', 'branch_id' => '', 'families' => ['expenses', 'cashups'], 'chunk_size' => '50']));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(base_url('accounting_periods/backfill/view/' . $result['id']), $result['redirect']);

        $batch = $this->backfill->batch((int) $result['id'], $this->backfillManager);

        $this->assertSame(BackfillStatus::Collecting, $batch->status);
        $this->assertSame(50, $batch->chunkSize);
        $this->assertSame(['expenses', 'cashups'], array_map(static fn ($family): string => $family->value, $batch->families));
    }

    public function testPostCreate_ExplainsWhyABatchIsRefused(): void
    {
        $this->backfill->create(['name' => 'Open', 'from' => '2026-01-01', 'to' => '2026-12-31'], $this->backfillManager);

        $result = $this->json($this->post('/accounting_periods/backfill/create', ['name' => 'Same documents', 'from' => '2026-03-01', 'to' => '2026-03-31']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Open', $result['message']);

        $bad = $this->json($this->post('/accounting_periods/backfill/create', ['name' => '', 'from' => '2026-03-01', 'to' => '2026-03-31']));

        $this->assertSame(lang('Accounting.error_backfill_name_invalid'), $bad['message']);
    }

    public function testPostCreate_RefusesAUserWithoutThePermission(): void
    {
        $this->actAs([]);

        $result = $this->json($this->post('/accounting_periods/backfill/create', ['name' => 'Nope', 'from' => '2026-03-01', 'to' => '2026-03-31']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame(0, $this->countRows('accounting_backfill_batches'));
    }

    public function testGetView_ShowsTheFiguresTheReasonsTheTotalsAndTheJournalsOfThePreview(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->legacyExpense('0.50', '0', '2026-03-12');

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->update(['journal_date' => '2026-03-11']);

        $batch    = $this->previewed();
        $response = $this->get('/accounting_periods/backfill/view/' . $batch->id);

        $response->assertStatus(200);
        $response->assertSee('backfill_status');
        $response->assertSee(lang('Accounting_backfill.status_preview'));
        $response->assertSee($batch->fingerprint);
        $response->assertSee('backfill_reasons');
        $response->assertSee(lang('Accounting_backfill.refusal_before_opening'));
        $response->assertSee('backfill_totals_table');
        $response->assertSee('backfill_documents_table');
        $response->assertSee(lang('Accounting_backfill.outcome_refused'));
    }

    public function testGetView_ListsTheJournalsTheBatchWouldPostWithTheirLines(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);

        $batch    = $this->previewed();
        $response = $this->get('/accounting_periods/backfill/view/' . $batch->id);

        $response->assertSee('backfill_journals_table');
        $response->assertSee('sale #');
        $response->assertSee('sale_payment #');
        $response->assertSee('816.50');
    }

    public function testGetView_FiltersTheDocumentsByTheirResult(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('0.01', '0', '2019-03-12');

        $batch = $this->previewed(['from' => '2019-01-01']);

        $refused = $this->get('/accounting_periods/backfill/view/' . $batch->id . '?outcome=refused');
        $ready   = $this->get('/accounting_periods/backfill/view/' . $batch->id . '?outcome=ready');

        $refused->assertSee('backfill-refused');
        $refused->assertDontSee('backfill-ready');
        $ready->assertSee('backfill-ready');
        $ready->assertDontSee('backfill-refused');
    }

    public function testGetView_OffersApprovalAndCancellationOfAPreviewAndNeitherOfADecidedBatch(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();
        $view  = $this->get('/accounting_periods/backfill/view/' . $batch->id);

        $view->assertSee('id="backfill_approve"');
        $view->assertSee('id="backfill_cancel"');

        $this->approved($batch);

        $done = $this->get('/accounting_periods/backfill/view/' . $batch->id);

        $done->assertDontSee('id="backfill_approve"');
        $done->assertDontSee('id="backfill_cancel"');
    }

    public function testGetView_OffersToRunThePreviewOfABatchThatIsStillBeingPreviewed(): void
    {
        $batch = $this->previewed([], false);

        $this->get('/accounting_periods/backfill/view/' . $batch->id)->assertSee('backfill_preview_form');
    }

    public function testPostPreview_RunsThePreviewAndSaysWhereToGo(): void
    {
        $this->legacyExpense();

        $batch  = $this->previewed([], false);
        $result = $this->json($this->post('/accounting_periods/backfill/preview/' . $batch->id));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(lang('Accounting_backfill.previewed'), $result['message']);
        $this->assertSame(BackfillStatus::Preview, $this->statusOf($batch->id));
    }

    public function testGetApproveForm_IsNotFoundWithoutTheFingerprintOrForABatchThatIsNotPreviewed(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        foreach (['', '?fingerprint=short', '?fingerprint=' . str_repeat('G', 64)] as $query) {
            try {
                $this->get('/accounting_periods/backfill/approve_form/' . $batch->id . $query);
                $this->fail('The form opened for ' . $query);
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->backfill->cancel($batch->id, $this->backfillManager);

        $this->expectException(PageNotFoundException::class);
        $this->get('/accounting_periods/backfill/approve_form/' . $batch->id . '?fingerprint=' . $batch->fingerprint);
    }

    public function testGetApproveForm_CarriesTheFingerprintAndTheTokenAgainstForgedRequestsWhenTheProtectionIsOn(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        service('filters')->enableFilters(['csrf'], 'before');

        try {
            $html = view('accounting_periods/backfill_approve_form', ['batch' => $batch, 'fingerprint' => (string) $batch->fingerprint]);
        } finally {
            Services::resetSingle('filters');
        }

        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
        $this->assertStringContainsString('value="' . $batch->fingerprint . '"', $html);
        $this->assertStringContainsString('name="confirm"', $html);
    }

    public function testGetCancelForm_CarriesTheTokenAndAConfirmationWhenTheProtectionIsOn(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        service('filters')->enableFilters(['csrf'], 'before');

        try {
            $html = view('accounting_periods/backfill_cancel_form', ['batch' => $batch, 'documents' => 1, 'journals' => 1, 'approving' => false]);
        } finally {
            Services::resetSingle('filters');
        }

        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
        $this->assertStringContainsString('name="confirm"', $html);
    }

    public function testPostCreate_PostApproveAndPostCancelAreCoveredByTheGlobalProtectionAgainstForgedRequests(): void
    {
        $globals = (new ReflectionClass(Filters::class))->getDefaultProperties()['globals'];

        $this->assertArrayHasKey('csrf', $globals['before']);

        foreach (['accounting_periods/backfill/create', 'accounting_periods/backfill/preview/5', 'accounting_periods/backfill/approve/5', 'accounting_periods/backfill/cancel/5'] as $uri) {
            $this->assertSame(0, preg_match('#^(' . $globals['before']['csrf']['except'] . ')#', $uri), "{$uri} is not excepted");
        }
    }

    public function testPostApprove_NeedsTheConfirmationAndPostsNothingWithout(): void
    {
        $this->legacyExpense();

        $batch  = $this->previewed();
        $result = $this->json($this->post('/accounting_periods/backfill/approve/' . $batch->id, ['fingerprint' => $batch->fingerprint]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting_backfill.confirm_required'), $result['message']);
        $this->assertSame(BackfillStatus::Preview, $this->statusOf($batch->id));
    }

    public function testPostApprove_PostsThePreviewAndSaysWhereToGo(): void
    {
        $this->legacyExpense();

        $batch  = $this->previewed();
        $result = $this->json($this->post('/accounting_periods/backfill/approve/' . $batch->id, ['fingerprint' => $batch->fingerprint, 'confirm' => '1']));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(base_url('accounting_periods/backfill/view/' . $batch->id), $result['redirect']);
        $this->assertSame(BackfillStatus::Approved, $this->statusOf($batch->id));
        $this->assertSame('posted', $this->batchJournals($batch->id)[0]['status']);
    }

    public function testPostApprove_ExplainsWhyItWasRefused(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->setConfigValue('accounting_backfill_enabled', '0');

        $off = $this->json($this->post('/accounting_periods/backfill/approve/' . $batch->id, ['fingerprint' => $batch->fingerprint, 'confirm' => '1']));

        $this->assertSame(lang('Accounting.error_backfill_disabled'), $off['message']);

        $this->setConfigValue('accounting_backfill_enabled', '1');

        $stale = $this->json($this->post('/accounting_periods/backfill/approve/' . $batch->id, ['fingerprint' => str_repeat('0', 64), 'confirm' => '1']));

        $this->assertSame(lang('Accounting.error_backfill_preview_changed'), $stale['message']);
    }

    public function testPostApprove_RefusesAUserWithoutThePermission(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->actAs([]);

        $result = $this->json($this->post('/accounting_periods/backfill/approve/' . $batch->id, ['fingerprint' => $batch->fingerprint, 'confirm' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(BackfillStatus::Preview, $this->statusOf($batch->id));
    }

    public function testPostCancel_NeedsTheConfirmationThenRemovesThePreviews(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        $refused = $this->json($this->post('/accounting_periods/backfill/cancel/' . $batch->id));

        $this->assertFalse($refused['success']);
        $this->assertSame([], array_diff(array_column($this->batchJournals($batch->id), 'status'), ['preview']));

        $result = $this->json($this->post('/accounting_periods/backfill/cancel/' . $batch->id, ['confirm' => '1']));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(BackfillStatus::Cancelled, $this->statusOf($batch->id));
        $this->assertSame([], $this->batchJournals($batch->id));
    }

    public function testPostCancel_RefusesABatchThatWasApproved(): void
    {
        $this->legacyExpense();

        $batch = $this->approved($this->previewed());
        $result = $this->json($this->post('/accounting_periods/backfill/cancel/' . $batch->id, ['confirm' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame('posted', $this->batchJournals($batch->id)[0]['status']);
    }

    public function testGetView_SaysWhichDocumentsWillBeStampedWithTheBranchWhenTheBatchIsApproved(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->get('/accounting_periods/backfill/view/' . $batch->id)->assertSee(lang('Accounting_backfill.stamp_note'));
    }

    public function testGetCancelForm_IsNotFoundForABatchThatWasApproved(): void
    {
        $this->legacyExpense();

        $batch = $this->approved($this->previewed());

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/backfill/cancel_form/' . $batch->id);
    }

    public function testGetCancelForm_ShowsTheFormOfABatchThatIsPreviewed(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->get('/accounting_periods/backfill/cancel_form/' . $batch->id)->assertSee('backfill_cancel_form');
    }

    public function testGetView_OffersToResumeAndToEndAnApprovalThatIsHalfDone(): void
    {
        foreach (['100', '200', '300'] as $index => $amount) {
            $this->legacyExpense($amount, '0', '2026-03-1' . ($index + 2));
        }

        $batch = $this->previewed(['chunk_size' => 1]);

        $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);

        $view = $this->get('/accounting_periods/backfill/view/' . $batch->id);

        $view->assertSee(lang('Accounting_backfill.status_approving'));
        $view->assertSee('id="backfill_approve"');
        $view->assertSee('id="backfill_cancel"');
        $view->assertSee(lang('Accounting_backfill.approved_count'));
        $view->assertSee('1 / 3');
    }

    public function testGetApproveForm_OpensForABatchThatIsBeingApproved(): void
    {
        $this->legacyExpense();
        $this->legacyExpense('60', '0', '2026-03-13');

        $batch = $this->previewed(['chunk_size' => 1]);

        $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);

        $this->get('/accounting_periods/backfill/approve_form/' . $batch->id . '?fingerprint=' . $batch->fingerprint)->assertSee('backfill_approve_form');
    }

    public function testGetCancelForm_SaysWhatIsLeftToRemoveOfABatchThatIsBeingApproved(): void
    {
        foreach (['100', '200', '300'] as $index => $amount) {
            $this->legacyExpense($amount, '0', '2026-03-1' . ($index + 2));
        }

        $batch = $this->previewed(['chunk_size' => 1]);

        $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);

        $form = $this->get('/accounting_periods/backfill/cancel_form/' . $batch->id);

        $form->assertSee('backfill_cancel_form');
        $form->assertSee(lang('Accounting_backfill.cancel_summary', [$batch->name, '2', '2']));
        $form->assertSee(lang('Accounting_backfill.cancel_warning_approving'));
        $form->assertDontSee(lang('Accounting_backfill.cancel_warning'));
    }

    public function testPostCancel_EndsAnApprovalThatIsHalfDoneAndKeepsWhatWasPosted(): void
    {
        $this->legacyExpense();
        $this->legacyExpense('60', '0', '2026-03-13');

        $batch = $this->previewed(['chunk_size' => 1]);

        $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);

        $result = $this->json($this->post('/accounting_periods/backfill/cancel/' . $batch->id, ['confirm' => '1']));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(BackfillStatus::Cancelled, $this->statusOf($batch->id));
        $this->assertSame(['posted'], array_column($this->batchJournals($batch->id), 'status'));

        $view = $this->get('/accounting_periods/backfill/view/' . $batch->id);

        $view->assertSee(lang('Accounting_backfill.status_cancelled'));
        $view->assertSee(lang('Accounting_backfill.approved_count'));
        $view->assertDontSee('id="backfill_cancel"');
    }

    public function testGetCancelForm_CountsWhatABatchHoldsWhileItIsStillBeingPreviewed(): void
    {
        $this->legacyExpense();
        $this->legacyExpense('60', '0', '2026-03-13');

        $batch = $this->previewed(['chunk_size' => 1], false);

        $this->backfill->preview($batch->id, $this->backfillManager, 2);

        $this->assertSame(BackfillStatus::Collecting, $this->statusOf($batch->id));
        $this->get('/accounting_periods/backfill/cancel_form/' . $batch->id)->assertSee(lang('Accounting_backfill.cancel_summary', [$batch->name, '2', '2']));
    }

    public function testGetIndex_ShowsTheFiguresOfABatchThatIsStillBeingPreviewed(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('200', '0', '2026-03-13');
        $this->legacyExpense('0.01', '0', '2019-03-14');

        $batch = $this->previewed(['chunk_size' => 1, 'from' => '2019-01-01'], false);

        $this->backfill->preview($batch->id, $this->backfillManager, 3);

        $this->assertSame(BackfillStatus::Collecting, $this->statusOf($batch->id));

        $row = $this->accountingDb()->table('accounting_backfill_batches')->where('batch_id', $batch->id)->get()->getRow();

        $this->assertSame([2, 1, 2], [(int) $row->ready_count, (int) $row->refused_count, (int) $row->journal_count]);
        $this->get('/accounting_periods/backfill')->assertSee('<td class="text-end">2</td>');
    }

    public function testGetView_TakesAPageThatIsNotANumberOrBeyondTheLastForAPageThatExists(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        foreach (['99999999999999999999', '1000000', '0', '-5', 'abc', '2.5', ''] as $page) {
            foreach (['documents_page', 'journals_page'] as $name) {
                $response = $this->get('/accounting_periods/backfill/view/' . $batch->id . '?' . $name . '=' . $page);

                $response->assertStatus(200);
                $response->assertSee('backfill_documents_table');
                $response->assertSee('backfill_journals_table');
            }
        }
    }

    public function testGetView_KeepsThePageOfTheOtherListWhenOneListChangesPage(): void
    {
        for ($count = 0; $count < 51; $count++) {
            $this->legacyExpense('10', '0', '2026-03-12');
        }

        $batch = $this->previewed();
        $view  = $this->get('/accounting_periods/backfill/view/' . $batch->id . '?documents_page=2&journals_page=2');

        $view->assertSee('documents_page=1&amp;journals_page=2');
        $view->assertSee('documents_page=2&amp;journals_page=1');
        $view->assertDontSee('backfill_no_documents');
    }

    public function testGetView_KeepsTheFilterOfTheDocumentsWhileTheJournalsChangePage(): void
    {
        for ($count = 0; $count < 51; $count++) {
            $this->legacyExpense('10', '0', '2026-03-12');
        }

        $this->legacyExpense('0.01', '0', '2019-03-12');

        $batch = $this->previewed(['from' => '2019-01-01']);
        $view  = $this->get('/accounting_periods/backfill/view/' . $batch->id . '?outcome=ready');

        $view->assertSee('documents_page=1&amp;journals_page=2&amp;outcome=ready');
    }

    public function testGetIndex_AndGetView_NameOnlyTheBranchesTheEmployeeWorksInOrTheBatchWritesIn(): void
    {
        $other = $this->createBranch('B2', 'Second branch');

        $this->legacyExpense('100', '0', '2026-03-12', 1);

        $batch   = $this->previewed();
        $message = lang('Accounting_backfill.blocker_opening_missing', ['B2']);

        $this->get('/accounting_periods/backfill')->assertSee($message);
        $this->get('/accounting_periods/backfill/view/' . $batch->id)->assertDontSee($message);

        $this->actAs([AccountingGuard::BACKFILL_MANAGE], [1]);
        $this->get('/accounting_periods/backfill')->assertDontSee($message);

        $this->actAsManager();
        $this->backfill->cancel($batch->id, $this->backfillManager);
        $this->legacyExpense('100', '0', '2026-03-12', $other);

        $inSecond = $this->previewed(['branch_id' => $other, 'name' => 'Second']);

        $this->get('/accounting_periods/backfill/view/' . $inSecond->id)->assertSee($message);
    }

    public function testGetNewForm_GroupsTheKindsOfDocumentsUnderALabelOfTheirOwn(): void
    {
        $form = $this->get('/accounting_periods/backfill/new_form');

        $form->assertSee('role="group" aria-labelledby="families_label"');
        $form->assertSee('id="families_label"');
        $this->assertNotSame(lang('Accounting_backfill.families'), lang('Accounting_backfill.documents'));
    }

    public function testGetView_KeepsTheFormOfThePreviewOutOfAParagraph(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed([], false);
        $html  = (string) $this->get('/accounting_periods/backfill/view/' . $batch->id)->getBody();

        $this->assertSame(0, preg_match('/<p[^>]*>\s*<form/', $html));
        $this->assertStringContainsString('<div class="print_hide mb-3">', $html);
        $this->assertLessThan(strpos($html, 'id="backfill_cancel"'), strpos($html, 'id="backfill_preview_form"'));
    }
}
