<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\OpeningImport\OpeningImportFiles;
use App\Libraries\Accounting\OpeningImport\OpeningImportParser;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Filters;
use ReflectionClass;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen that enters the opening balances of customers and suppliers from a file: who may open it, what the
 * preview shows, that a file with errors cannot be approved, that the approval goes on in steps and shows how far it is,
 * and the sample and the report the screen gives as files.
 *
 * @internal
 */
final class AccountingOpeningImportControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const DAY = '2024-12-31';

    private int $manager;

    /**
     * @var list<string>
     */
    private array $tokens = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->setConfigValue('accounting_customer_opening', '1');

        $this->manager = $this->actAs([AccountingGuard::OPENING_MANAGE, AccountingGuard::PAYABLES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->accountingDb()->table('receivings')->where('receiving_type', 'opening')->delete();

        $files = new OpeningImportFiles();

        foreach ($this->tokens as $token) {
            $files->forget($token);
        }

        service('superglobals')->setFilesArray([]);
        $this->stopBuying();
        $this->setConfigValue('accounting_customer_opening', '0');

        parent::tearDown();
    }

    /**
     * @param list<string>   $permissions
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

    /**
     * @param list<array<int, string>> $rows
     */
    private function csv(array $rows): string
    {
        return "party,amount,date,note\n" . implode("\n", array_map(static fn (array $row): string => implode(',', $row), $rows)) . "\n";
    }

    private function kept(string $text): string
    {
        $token          = (new OpeningImportFiles())->store($text);
        $this->tokens[] = $token;

        return $token;
    }

    private function fingerprintOf(string $kind, string $token): string
    {
        preg_match('/id="opening_import_fingerprint">([0-9a-f]{64})</', $this->get('/accounting_periods/opening_import/preview/' . $kind . '/' . $token)->getBody(), $found);

        return $found[1];
    }

    public function testGetIndex_ShowsTheFormTheSampleAndTheSignOfTheAmountToSomeoneWhoMayImport(): void
    {
        $response = $this->get('/accounting_periods/opening_import');

        $response->assertStatus(200);
        $response->assertSee('id="opening_import_form"');
        $response->assertSee('id="opening_import_sample_customers"');
        $response->assertSee('id="opening_import_sample_suppliers"');
        $response->assertSee(lang('Accounting_opening_import.sign_customers'));
        $response->assertSee(lang('Accounting_opening_import.sign_suppliers'));
        $response->assertSee('value="customers"');
        $response->assertSee('value="suppliers"');
        $response->assertDontSee('id="opening_import_off"');
    }

    public function testGetIndex_OffersOnlyTheKindsThePermissionsOfTheEmployeeCover(): void
    {
        $this->actAs([AccountingGuard::PAYABLES_MANAGE]);

        $response = $this->get('/accounting_periods/opening_import');

        $response->assertSee('value="suppliers"');
        $response->assertDontSee('value="customers"');
    }

    public function testGetIndex_ShowsOnlyTheNoteToSomeoneWithNeitherPermissionOrLimitedToABranch(): void
    {
        $this->actAs([]);

        $none = $this->get('/accounting_periods/opening_import');

        $none->assertSee('id="opening_import_permission_note"');
        $none->assertDontSee('id="opening_import_form"');

        $this->actAs([AccountingGuard::OPENING_MANAGE, AccountingGuard::PAYABLES_MANAGE], [1]);

        $this->get('/accounting_periods/opening_import')->assertSee('id="opening_import_permission_note"');
    }

    public function testGetIndex_WarnsThatTheBalancesOfCustomersAreNotTurnedOn(): void
    {
        $this->setConfigValue('accounting_customer_opening', '0');

        $this->get('/accounting_periods/opening_import')->assertSee('id="opening_import_off"');
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening_import');
    }

    public function testGetSample_GivesTheFileOfTheKindAsADownload(): void
    {
        $customers = $this->get('/accounting_periods/opening_import/sample/customers');

        $customers->assertStatus(200);
        $customers->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('opening-balances-customers.csv', $customers->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF# ", (string) $customers->response()->getBody());
        $this->assertStringContainsString("party,amount,date,note\n", (string) $customers->response()->getBody());
        $this->assertStringContainsString(lang('Accounting_opening_import.sample_customers_amount'), (string) $customers->response()->getBody());
        $this->assertStringContainsString(lang('Accounting_opening_import.sample_suppliers_amount'), (string) $this->get('/accounting_periods/opening_import/sample/suppliers')->response()->getBody());
    }

    public function testGetSample_IsNotFoundForAKindTheEmployeeMayNotImportAndForANonsenseKind(): void
    {
        $this->actAs([AccountingGuard::OPENING_MANAGE]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening_import/sample/suppliers');
    }

    public function testGetSample_IsNotFoundForAnUnknownKind(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening_import/sample/vendors');
    }

    public function testPostUpload_ReadsTheFileKeepsItAndSaysWhereThePreviewIs(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'import');
        $text = $this->csv([[(string) $this->customerId, '250.50', self::DAY, 'Invoice 7']]);

        file_put_contents($file, $text);
        service('superglobals')->setFilesArray(['file' => ['name' => 'customers.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]]);

        try {
            $result = $this->json($this->post('/accounting_periods/opening_import/upload', ['kind' => 'customers']));
        } finally {
            unlink($file);
        }

        $token          = hash('sha256', $text);
        $this->tokens[] = $token;

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(base_url('accounting_periods/opening_import/preview/customers/' . $token), $result['redirect']);
        $this->assertSame($text, (new OpeningImportFiles())->read($token));
        $this->assertSame(0, $this->accountingDb()->table('customer_openings')->countAllResults());
    }

    public function testPostUpload_SaysWhatIsWrongWithAFileThatIsNotInTheShapeOfTheSample(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'import');

        file_put_contents($file, "name,note\nAli,x\n");
        service('superglobals')->setFilesArray(['file' => ['name' => 'wrong.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]]);

        try {
            $result = $this->json($this->post('/accounting_periods/opening_import/upload', ['kind' => 'customers']));
        } finally {
            unlink($file);
        }

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_opening_import_file_invalid'), $result['message']);
    }

    public function testPostUpload_AsksForAFileWhenThereIsNoneAndRefusesOneOverTheLimitWithoutReadingIt(): void
    {
        $none = $this->json($this->post('/accounting_periods/opening_import/upload', ['kind' => 'customers']));

        $this->assertFalse($none['success']);
        $this->assertSame(lang('Accounting_opening_import.file_required'), $none['message']);

        $file = tempnam(sys_get_temp_dir(), 'import');

        file_put_contents($file, 'x');
        service('superglobals')->setFilesArray(['file' => ['name' => 'huge.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => OpeningImportParser::MAX_BYTES + 1]]);

        try {
            $huge = $this->json($this->post('/accounting_periods/opening_import/upload', ['kind' => 'customers']));
        } finally {
            unlink($file);
        }

        $this->assertFalse($huge['success']);
        $this->assertSame(lang('Accounting.error_opening_import_file_too_big', [(string) OpeningImportParser::MAX_ROWS]), $huge['message']);
    }

    public function testPostUpload_RefusesSomeoneWithoutThePermissionOfTheKind(): void
    {
        $this->actAs([AccountingGuard::PAYABLES_MANAGE]);

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_periods/opening_import/upload', ['kind' => 'customers']);
    }

    public function testGetPreview_ShowsTheRowsTheJournalAndTheTotalsAndOffersTheApproval(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $token = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, 'Invoice 7'], [(string) $other, '-40', self::DAY, '']]));

        $response = $this->get('/accounting_periods/opening_import/preview/customers/' . $token);

        $response->assertStatus(200);
        $response->assertSee('id="opening_import_table"');
        $response->assertSee('Omar Khaled');
        $response->assertSee('Hind Saad');
        $response->assertSee('250.50');
        $response->assertSee('id="opening_import_approve"');
        $response->assertSee('id="opening_import_export"');
        $response->assertDontSee('id="opening_import_blocked"');
        $this->assertStringContainsString('<dd class="col-sm-9" id="opening_import_pending">2</dd>', $response->getBody());
        $this->assertStringContainsString('<dd class="col-sm-9" id="opening_import_errors">0</dd>', $response->getBody());
        $this->assertStringContainsString(lang('Accounting_opening_import.totals_customers_positive'), $response->getBody());
        $this->assertStringContainsString((string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->customerAccount())->get()->getRow()->name, $response->getBody());
    }

    public function testGetPreview_ListsTheProblemOfEachRowAndOffersNoApprovalWhileAnyRowHasOne(): void
    {
        $token = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, ''], ['Nobody Atall', '5', self::DAY, ''], [(string) $this->customerId, '0', self::DAY, '']]));

        $response = $this->get('/accounting_periods/opening_import/preview/customers/' . $token);

        $response->assertSee('id="opening_import_blocked"');
        $response->assertDontSee('id="opening_import_approve"');
        $response->assertSee(lang('Accounting_opening_import.error_party_unknown'));
        $response->assertSee(lang('Accounting_opening_import.error_amount_zero'));
        $response->assertSee(lang('Accounting_opening_import.error_party_duplicate'));
        $this->assertStringContainsString('<dd class="col-sm-9" id="opening_import_errors">3</dd>', $response->getBody());
    }

    public function testGetPreview_FiltersTheRowsByStateAndPagesThem(): void
    {
        $token = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, ''], ['Nobody Atall', '5', self::DAY, '']]));

        $errors = $this->get('/accounting_periods/opening_import/preview/customers/' . $token, ['state' => 'error']);

        $errors->assertSee('Nobody Atall');
        $errors->assertDontSee('Omar Khaled');
        $this->get('/accounting_periods/opening_import/preview/customers/' . $token, ['state' => 'posted'])->assertSee('id="opening_import_no_rows"');
    }

    public function testGetPreview_SaysThereIsNothingToApproveWhenEveryRowIsPostedAlready(): void
    {
        (new CustomerOpening())->create($this->customerId, CustomerOpening::OWES_US, '250.50', self::DAY, '', $this->manager);

        $token    = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, '']]));
        $response = $this->get('/accounting_periods/opening_import/preview/customers/' . $token);

        $response->assertSee('id="opening_import_nothing"');
        $response->assertDontSee('id="opening_import_approve"');
    }

    public function testGetPreview_SendsBackToTheFormWhenTheFileIsNotKeptAnyMore(): void
    {
        $response = $this->get('/accounting_periods/opening_import/preview/customers/' . str_repeat('a', 64));

        $response->assertRedirectTo(base_url('accounting_periods/opening_import'));
        $this->assertSame(lang('Accounting_opening_import.file_missing'), session()->getFlashdata('accounting_notice'));
    }

    public function testGetPreview_IsNotFoundForSomeoneWithoutThePermissionOfTheKind(): void
    {
        $token = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, '']]));

        $this->actAs([AccountingGuard::PAYABLES_MANAGE]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening_import/preview/customers/' . $token);
    }

    public function testGetApproveForm_OpensOnlyWithTheFingerprintOfAFileThatHasRowsToPost(): void
    {
        $token       = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, '']]));
        $fingerprint = $this->fingerprintOf('customers', $token);

        $form = $this->get('/accounting_periods/opening_import/approve_form/customers/' . $token, ['fingerprint' => $fingerprint]);

        $form->assertStatus(200);
        $form->assertSee('id="approve_progress"');
        $form->assertSee('name="fingerprint" value="' . $fingerprint . '"');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening_import/approve_form/customers/' . $token, ['fingerprint' => 'nonsense']);
    }

    public function testPostApprove_NeedsTheConfirmationAndPostsNothingWithout(): void
    {
        $token       = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, '']]));
        $fingerprint = $this->fingerprintOf('customers', $token);

        $result = $this->json($this->post('/accounting_periods/opening_import/approve', ['kind' => 'customers', 'token' => $token, 'fingerprint' => $fingerprint]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting_backfill.confirm_required'), $result['message']);
        $this->assertSame(0, $this->accountingDb()->table('customer_openings')->countAllResults());
    }

    public function testPostApprove_PostsTheFileAndSaysWhereToGo(): void
    {
        $token       = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, '']]));
        $fingerprint = $this->fingerprintOf('customers', $token);

        $result = $this->json($this->post('/accounting_periods/opening_import/approve', ['kind' => 'customers', 'token' => $token, 'fingerprint' => $fingerprint, 'confirm' => '1']));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertFalse($result['continue']);
        $this->assertSame(base_url('accounting_periods/opening_import/preview/customers/' . $token), $result['redirect']);
        $this->assertSame(lang('Accounting_opening_import.approved', ['1', '0']), $result['message']);
        $this->assertSame(1, $this->accountingDb()->table('customer_openings')->where('status', 'posted')->countAllResults());
    }

    public function testPostApprove_WorksInStepsAndSaysHowFarEachOneGotUntilEveryRowIsPosted(): void
    {
        $rows = [];

        for ($number = 1; $number <= 60; $number++) {
            $rows[] = [(string) $this->anotherCustomer('Step', 'Customer ' . $number), '10', self::DAY, ''];
        }

        $token       = $this->kept($this->csv($rows));
        $fingerprint = $this->fingerprintOf('customers', $token);
        $responses   = [];

        do {
            $result      = $this->json($this->post('/accounting_periods/opening_import/approve', ['kind' => 'customers', 'token' => $token, 'fingerprint' => $fingerprint, 'confirm' => '1', 'steps' => '1']));
            $responses[] = $result;
        } while ($result['success'] && $result['continue'] && count($responses) < 10);

        $this->assertCount(3, $responses);
        $this->assertSame([true, 25, 60, lang('Accounting_opening_import.approve_progress', ['25', '60'])], [$responses[0]['continue'], $responses[0]['done'], $responses[0]['total'], $responses[0]['message']]);
        $this->assertSame(50, $responses[1]['done']);
        $this->assertFalse($responses[2]['continue']);
        $this->assertSame(60, $this->accountingDb()->table('customer_openings')->where('status', 'posted')->countAllResults());
    }

    public function testPostApprove_ExplainsWhyItWasRefused(): void
    {
        $token       = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, '']]));
        $fingerprint = $this->fingerprintOf('customers', $token);

        $stale = $this->json($this->post('/accounting_periods/opening_import/approve', ['kind' => 'customers', 'token' => $token, 'fingerprint' => str_repeat('0', 64), 'confirm' => '1']));

        $this->assertSame(lang('Accounting.error_opening_import_changed'), $stale['message']);

        $missing = $this->json($this->post('/accounting_periods/opening_import/approve', ['kind' => 'customers', 'token' => str_repeat('b', 64), 'fingerprint' => $fingerprint, 'confirm' => '1']));

        $this->assertSame(lang('Accounting_opening_import.file_missing'), $missing['message']);

        $bad    = $this->kept($this->csv([['Nobody Atall', '5', self::DAY, '']]));
        $errors = $this->json($this->post('/accounting_periods/opening_import/approve', ['kind' => 'customers', 'token' => $bad, 'fingerprint' => $this->fingerprintOf('customers', $bad), 'confirm' => '1']));

        $this->assertSame(lang('Accounting.error_opening_import_has_errors', ['1']), $errors['message']);
        $this->assertSame(0, $this->accountingDb()->table('customer_openings')->countAllResults());
    }

    public function testPostApprove_RefusesSomeoneWithoutThePermissionOfTheKind(): void
    {
        $token       = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, '']]));
        $fingerprint = $this->fingerprintOf('customers', $token);

        $this->actAs([AccountingGuard::PAYABLES_MANAGE]);

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_periods/opening_import/approve', ['kind' => 'customers', 'token' => $token, 'fingerprint' => $fingerprint, 'confirm' => '1']);
    }

    public function testPostApprove_PostsTheBalancesOfSuppliers(): void
    {
        $token       = $this->kept($this->csv([[(string) $this->supplierId, '900', self::DAY, 'Open invoices']]));
        $fingerprint = $this->fingerprintOf('suppliers', $token);

        $result = $this->json($this->post('/accounting_periods/opening_import/approve', ['kind' => 'suppliers', 'token' => $token, 'fingerprint' => $fingerprint, 'confirm' => '1']));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(1, $this->accountingDb()->table('receivings')->where('receiving_type', 'opening')->countAllResults());
    }

    public function testGetExport_GivesTheRowsWithTheirStateAndProblemsAsAFile(): void
    {
        $token    = $this->kept($this->csv([[(string) $this->customerId, '250.50', self::DAY, 'Invoice 7'], ['Nobody Atall', '5', self::DAY, '']]));
        $response = $this->get('/accounting_periods/opening_import/export/customers/' . $token);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('opening-balances-customers-rows.csv', $response->response()->getHeaderLine('Content-Disposition'));

        $lines = array_map('str_getcsv', explode("\n", trim(substr((string) $response->response()->getBody(), 3))));

        $this->assertSame(['line', 'party', 'party_id', 'name', 'amount', 'date', 'note', 'state', 'errors'], $lines[0]);
        $this->assertSame(['2', (string) $this->customerId, (string) $this->customerId, 'Omar Khaled', '250.5000', self::DAY, 'Invoice 7', 'pending', ''], $lines[1]);
        $this->assertSame('error', $lines[2][7]);
        $this->assertStringContainsString(lang('Accounting_opening_import.error_party_unknown'), $lines[2][8]);
    }

    public function testPostUploadAndPostApprove_AreCoveredByTheGlobalProtectionAgainstForgedRequests(): void
    {
        $globals = (new ReflectionClass(Filters::class))->getDefaultProperties()['globals'];

        $this->assertArrayHasKey('csrf', $globals['before']);

        foreach (['accounting_periods/opening_import/upload', 'accounting_periods/opening_import/approve'] as $uri) {
            $this->assertSame(0, preg_match('#\A(' . implode('|', array_map(static fn (string $path): string => preg_quote($path, '#'), (array) $globals['before']['csrf']['except'])) . ')\z#', $uri), "{$uri} is not excepted");
        }
    }
}
