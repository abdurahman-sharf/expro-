<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\EInvoicing\EInvoiceSettings;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\EInvoiceFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screens of the candidate layer of electronic invoicing: who opens them, what they say, what the
 * settings, the issue and the file need, and what the page of an invoice shows of its document.
 */
class AccountingEInvoicingControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use EInvoiceFixtureTrait;
    use EInvoiceSaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->configureEInvoicing();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearEInvoices();
        $this->restoreEInvoicingConfig();
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();
        $this->accountingDb()->table('app_config')->where('key', 'accounting_debit_note')->delete();
        $this->removeTemporaryDirectories();
        $this->stopSelling();
        $this->resetBranches();

        parent::tearDown();
    }

    private function manager(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_einvoicing', AccountingGuard::EINVOICING_MANAGE], $branchIds);

        $this->actAs($personId);

        return $personId;
    }

    private function reader(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_einvoicing'], $branchIds);

        $this->actAs($personId);

        return $personId;
    }

    private function issued(): int
    {
        return (new EInvoiceService())->issueSale($this->referenceSale(), null, '', null)->documentId;
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->reader();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_einvoicing');
    }

    public function testGetIndex_SaysLoudlyThatTheLayerIsACandidateAndNotACertification(): void
    {
        $this->reader();

        $page = $this->get('/accounting_einvoicing');

        $page->assertStatus(200);
        $page->assertSee('id="einvoice_candidate_banner"');
        $page->assertSee('Candidate, not certified.');
        $page->assertSee('not a certification or a claim of compliance');
    }

    public function testGetIndex_ShowsTheReadinessWithoutTheFormToAReaderWhoMayNotManage(): void
    {
        $this->reader();

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('data-check="seller_vat" data-status="pass"');
        $page->assertSee('data-check="transport" data-status="warn"');
        $page->assertDontSee('id="einvoice_save"');
        $page->assertSee('Changing these settings needs the permission');
    }

    public function testGetIndex_OffersTheSaveButtonToTheManagerAndListsTheCustomersWithoutIdentification(): void
    {
        $this->manager();

        $company = $this->customerId;

        $this->accountingDb()->table('customers')->where('person_id', $company)->update(['customer_type' => 'commercial']);

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('id="einvoice_save"');
        $page->assertSee('data-customer="' . $company . '"');
        $page->assertSee('No tax number and no identification');
    }

    public function testGetIndex_SaysTheLayerIsOffUntilItIsSwitchedOn(): void
    {
        $this->reader();
        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('id="einvoice_state_badge"');
        $this->assertMatchesRegularExpression('#id="einvoice_state_badge">\s*Off\s*<#', $page->response()->getBody());
    }

    public function testGetIndex_ListsTheIssuedDocumentsWithTheDownloadLinkOnlyForWhoMayTakeTheFile(): void
    {
        $documentId = $this->issued();

        $this->reader();

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('data-document="' . $documentId . '"');
        $page->assertDontSee('accounting_einvoicing/xml/' . $documentId);

        $this->manager();

        $this->get('/accounting_einvoicing')->assertSee('accounting_einvoicing/xml/' . $documentId);
    }

    public function testGetIndex_ListsOnlyTheDocumentsOfTheBranchesTheReaderMaySee(): void
    {
        $documentId = $this->issued();
        $other      = $this->createBranch('B2');

        $this->reader([$other]);

        $this->get('/accounting_einvoicing')->assertDontSee('data-document="' . $documentId . '"');
    }

    public function testPostSaveSettings_SavesForTheManagerAndTurnsTheLayerOn(): void
    {
        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/save_settings', ['enabled' => '1', 'seller_city' => 'Jeddah']));

        $this->assertTrue($result['success']);
        $this->assertSame('Jeddah', (new EInvoiceSettings())->all()['seller_city']);
        $this->assertTrue((new EInvoiceSettings())->isEnabled());
    }

    public function testPostSaveSettings_SaysNothingChangedWhenItDidNot(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/save_settings', ['enabled' => '1', 'seller_city' => 'Riyadh']));

        $this->assertTrue($result['success']);
        $this->assertSame('Nothing changed.', $result['message']);
    }

    public function testPostSaveSettings_RefusesToChangeAnythingWithoutThePermission(): void
    {
        $this->reader();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/save_settings', ['enabled' => '1', 'seller_city' => 'Jeddah']));

        $this->assertFalse($result['success']);
        $this->assertSame('Riyadh', (new EInvoiceSettings())->all()['seller_city']);
    }

    public function testPostSaveSettings_RefusesAManagerWhoWorksInSomeBranchesOnly(): void
    {
        $other = $this->createBranch('B2');

        $this->manager([$other]);

        $result = $this->jsonOf($this->post('/accounting_einvoicing/save_settings', ['seller_city' => 'Jeddah']));

        $this->assertFalse($result['success']);
        $this->assertSame('Riyadh', (new EInvoiceSettings())->all()['seller_city']);
    }

    public function testPostSaveSettings_RefusesAKeyPathInsideTheApplicationInTheLanguageInUse(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/save_settings', ['key_path' => WRITEPATH . 'key.pem']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('absolute and outside the application', $result['message']);
        $this->assertSame('', (new EInvoiceSettings())->all()['key_path']);
    }

    public function testPostIssue_IssuesTheDocumentOfASaleAndSaysItsNumberAndCounter(): void
    {
        $saleId = $this->referenceSale();

        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/issue/' . $saleId, ['subtype' => 'simplified']));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('counter 1', $result['message']);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
        $this->assertSame((int) $result['id'], (int) (new EInvoiceService())->documentOf('sale', $saleId)->documentId);
    }

    public function testPostIssue_IssuesATaxInvoiceWhenTheFormAsksForOne(): void
    {
        $saleId = $this->referenceSale($this->makeBusinessCustomer());

        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/issue/' . $saleId, ['subtype' => 'standard']));

        $this->assertTrue($result['success']);
        $this->assertSame('standard', (new EInvoiceService())->documentOf('sale', $saleId)->subtype->value);
    }

    public function testPostIssue_SaysInWordsWhyASaleCannotBeIssued(): void
    {
        $saleId = $this->referenceSale();

        $this->configureEInvoicing(['einvoice_seller_city' => '']);
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/issue/' . $saleId));

        $this->assertFalse($result['success']);
        $this->assertSame('The address of the company has no city.', $result['message']);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
    }

    public function testPostIssue_RefusesWithoutThePermissionAndStoresNothing(): void
    {
        $saleId = $this->referenceSale();

        $this->reader();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/issue/' . $saleId));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
    }

    public function testPostIssue_RefusesASaleOfABranchTheManagerDoesNotWorkIn(): void
    {
        $other  = $this->createBranch('B2');
        $this->branchLocation($other);
        $saleId = $this->invoiceInBranch($other, '115.00');

        $this->manager([1]);

        $result = $this->jsonOf($this->post('/accounting_einvoicing/issue/' . $saleId));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
    }

    public function testPostIssue_IssuesTheCreditNoteOfAReturnWithItsReason(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $this->nameItem($cycle['cream'], 'Cream');
        $this->nameItem($cycle['perfume'], 'Perfume');
        $return = $this->perfumeReturn($invoice, $cycle['perfume']);

        $this->manager();

        $this->assertFalse($this->jsonOf($this->post('/accounting_einvoicing/issue/' . $return, ['reason' => 'x']))['success']);
        $this->assertTrue($this->jsonOf($this->post('/accounting_einvoicing/issue/' . $invoice))['success']);
        $this->assertFalse($this->jsonOf($this->post('/accounting_einvoicing/issue/' . $return, ['reason' => '']))['success']);
        $this->assertTrue($this->jsonOf($this->post('/accounting_einvoicing/issue/' . $return, ['reason' => 'In case of goods or services refund.']))['success']);
        $this->assertSame(2, $this->countRows('einvoice_documents'));
    }

    public function testPostIssueDebitNote_IssuesTheDocumentOfADebitNote(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $invoice = $this->issued();
        $clerk   = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        $noteId  = (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(1), 'amount' => '100', 'tax_percent' => '15', 'note' => 'Freight'], $clerk);

        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/issue_debit_note/' . $noteId, ['original_document_id' => (string) $invoice, 'reason' => 'Amendment of the supply value which is pre-agreed upon between the supplier and consumer']));

        $this->assertTrue($result['success']);
        $this->assertSame('debit_note', (new EInvoiceService())->find((int) $result['id'])->kind->value);
    }

    public function testGetXml_GivesTheFileAsAnAttachmentNamedAfterTheSellerTheTimeAndTheNumber(): void
    {
        $documentId = $this->issued();
        $document   = (new EInvoiceService())->find($documentId);

        $this->manager();

        $response = $this->get('/accounting_einvoicing/xml/' . $documentId);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('attachment; filename="' . $document->fileName(self::SELLER_VAT) . '"', $response->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame($document->xml, $response->response()->getBody());
        $this->assertMatchesRegularExpression('/^399999999900003_\d{8}T\d{6}_[A-Za-z0-9-]+\.xml$/', $document->fileName(self::SELLER_VAT));
    }

    public function testGetXml_IsNotFoundWithoutThePermissionAndForAnotherBranchAndForNoDocument(): void
    {
        $documentId = $this->issued();

        $this->reader();

        try {
            $this->get('/accounting_einvoicing/xml/' . $documentId);
            $this->fail('The file was given without the permission.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $other = $this->createBranch('B2');

        $this->manager([$other]);

        try {
            $this->get('/accounting_einvoicing/xml/' . $documentId);
            $this->fail('The file of another branch was given.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->manager();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_einvoicing/xml/987654321');
    }

    public function testGetCustomers_PagesTheCustomersWithoutIdentificationAsJson(): void
    {
        $this->reader();

        $this->insertCustomer('Layla', 'Hassan');

        $result = $this->jsonOf($this->get('/accounting_einvoicing/customers?limit=1'));

        $this->assertCount(1, $result['rows']);
        $this->assertGreaterThanOrEqual(2, $result['total']);
        $this->assertArrayHasKey('businesses', $result);
    }

    public function testSalePanel_ShowsTheStatusAndTheIssueButtonOnTheInvoicePageWhileTheLayerIsOn(): void
    {
        $saleId = $this->referenceSale();

        $this->actAs($this->employeeWithGrants(['reports_sales', 'sales', 'sales_stock', AccountingGuard::EINVOICING_MANAGE]));

        $page = $this->get('/sales/invoice/' . $saleId);

        $page->assertSee('id="einvoice_panel" data-state="not_generated"');
        $page->assertSee('Not generated');
        $page->assertSee('Not submitted');
        $page->assertSee('Candidate');
        $page->assertSee('id="einvoice_issue"');
    }

    public function testSalePanel_ShowsTheDocumentAndTheDownloadOnceItIsIssuedAndWarnsWhenTheBooksMoved(): void
    {
        $documentId = $this->issued();
        $saleId     = (new EInvoiceService())->find($documentId)->sourceId;

        $this->actAs($this->employeeWithGrants(['reports_sales', 'sales', 'sales_stock', AccountingGuard::EINVOICING_MANAGE]));

        $page = $this->get('/sales/invoice/' . $saleId);

        $page->assertSee('data-state="generated"');
        $page->assertSee('id="einvoice_download"');
        $page->assertDontSee('id="einvoice_issue"');
        $page->assertDontSee('id="einvoice_drift"');

        $journal = $this->standing($saleId, 'revenue')[0];
        $this->accountingDb()->table('accounting_journal_lines')->where(['journal_id' => $journal['id'], 'account_id' => $this->customerAccount()])->update(['debit' => '900.00']);

        $this->get('/sales/invoice/' . $saleId)->assertSee('id="einvoice_drift"');
    }

    public function testSalePanel_OffersNoIssueButtonAndNoDownloadToSomeoneWithoutThePermission(): void
    {
        $documentId = $this->issued();
        $saleId     = (new EInvoiceService())->find($documentId)->sourceId;

        $this->actAs($this->employeeWithGrants(['reports_sales', 'sales', 'sales_stock']));

        $page = $this->get('/sales/invoice/' . $saleId);

        $page->assertSee('id="einvoice_panel"');
        $page->assertDontSee('id="einvoice_download"');
    }

    public function testSalePanel_IsAbsentWhileTheLayerIsOff(): void
    {
        $saleId = $this->referenceSale();

        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);
        $this->actAs($this->employeeWithGrants(['reports_sales', 'sales', 'sales_stock', AccountingGuard::EINVOICING_MANAGE]));

        $this->get('/sales/invoice/' . $saleId)->assertDontSee('id="einvoice_panel"');
    }
}
