<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\EInvoicing\EInvoiceReadiness;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\Tax\CompanyTaxProfile;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;
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
        $this->accountingDb()->table('company_tax_profile')->where('company_id', 1)->update(['vat_registered' => null, 'vat_number' => null, 'ksa_mode' => 0, 'ksa_mode_effective_at' => null, 'ksa_mode_activated_at' => null, 'ksa_mode_activated_by' => null, 'ksa_mode_watermark' => null]);
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'tax_profile')->delete();
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

    private function ownRecordsManager(): int
    {
        $personId = $this->seesOwnRecordsOnly($this->employeeWithGrants(['accounting_einvoicing', AccountingGuard::EINVOICING_MANAGE]));

        $this->actAs($personId);

        return $personId;
    }

    private function manyDocuments(int $documentId, int $count): void
    {
        $table = $this->accountingDb()->prefixTable('einvoice_documents');

        for ($i = 1; $i <= $count; $i++) {
            $this->accountingDb()->query(
                "INSERT INTO {$table} (company_id, branch_id, source_type, source_id, doc_kind, subtype, document_number, uuid, icv, previous_hash, invoice_hash, issue_date, issue_time, currency, tax_exclusive, tax_amount, tax_inclusive, xml, qr_text, signed, profile) "
                . "SELECT company_id, branch_id, source_type, 900000 + {$i}, doc_kind, subtype, CONCAT('COPY-', {$i}), UUID(), icv + {$i}, previous_hash, invoice_hash, issue_date, issue_time, currency, tax_exclusive, tax_amount, tax_inclusive, xml, qr_text, signed, profile FROM {$table} WHERE document_id = ?",
                [$documentId],
            );
        }
    }

    private function decoded(TestResponse $page): string
    {
        return html_entity_decode($page->response()->getBody(), ENT_QUOTES);
    }

    private function occurrencesOf(string $html, string $needle): int
    {
        return substr_count($html, $needle);
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

    public function testGetIndex_ShowsThePathsOfTheKeyAndTheCertificateOnlyToWhoMayManageTheSettings(): void
    {
        $this->configureEInvoicing(['einvoice_key_path' => '/srv/secrets/zatca/private-key.pem', 'einvoice_certificate_path' => '/srv/secrets/zatca/certificate.pem']);

        $this->reader();

        $page = $this->get('/accounting_einvoicing');

        $page->assertDontSee('/srv/secrets/zatca');
        $page->assertDontSee('id="key_path"');
        $page->assertDontSee('id="certificate_path"');

        $other = $this->createBranch('B2');

        $this->manager([$other]);

        $this->get('/accounting_einvoicing')->assertDontSee('/srv/secrets/zatca');

        $this->manager();

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('id="key_path"');
        $page->assertSee('/srv/secrets/zatca/private-key.pem');
        $page->assertSee('/srv/secrets/zatca/certificate.pem');
    }

    public function testGetIndex_SaysInWordsWhetherEachCheckIsReadyWarningOrNotReadyBesideItsIcon(): void
    {
        $this->configureEInvoicing(['einvoice_seller_city' => '']);
        $this->reader();

        $body = $this->get('/accounting_einvoicing')->response()->getBody();

        $this->assertMatchesRegularExpression('#data-check="seller_vat" data-status="pass">.*?<span class="visually-hidden">Ready:</span>#s', $body);
        $this->assertMatchesRegularExpression('#data-check="transport" data-status="warn">.*?<span class="visually-hidden">Warning:</span>#s', $body);
        $this->assertMatchesRegularExpression('#data-check="seller_address" data-status="fail">.*?<span class="visually-hidden">Not ready:</span>#s', $body);
    }

    public function testGetIndex_SaysTheNameOfTheTransportAndOfTheSigningFailureInWordsAndNotAsCodes(): void
    {
        $this->reader();

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('No transport is in place (the transport that sends nothing)');
        $page->assertSee('Documents are issued unsigned (no key and no certificate are named)');
        $page->assertDontSee('not_configured');

        $this->configureEInvoicing(['einvoice_key_path' => WRITEPATH . 'key.pem', 'einvoice_certificate_path' => WRITEPATH . 'cert.pem']);

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('The key or the certificate cannot be used (the path is not allowed): documents are refused until it is put right.');
        $page->assertDontSee('key_path_unsafe');
        $page->assertDontSee('issued unsigned (the path');
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

    public function testGetIndex_OffersNoSaveButtonToAManagerWhoWorksInSomeBranchesOnly(): void
    {
        $other = $this->createBranch('B2');

        $this->manager([$other]);

        $page = $this->get('/accounting_einvoicing');

        $page->assertStatus(200);
        $page->assertDontSee('id="einvoice_save"');
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

    public function testGetIndex_ListsOnlyTheDocumentsOfTheirOwnRecordsToAReaderWhoSeesTheirOwnRecordsOnly(): void
    {
        $foreign = $this->issued();
        $own     = $this->ownRecordsManager();
        $mine    = (new EInvoiceService())->issueSale($this->ownedBy($this->referenceSale(), $own), null, '', null)->documentId;

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('data-document="' . $mine . '"');
        $page->assertDontSee('data-document="' . $foreign . '"');
    }

    public function testGetIndex_PagesTheIssuedDocumentsFiftyAtATimeAndClampsAnOffsetThatIsOutOfRange(): void
    {
        $documentId = $this->issued();

        $this->manyDocuments($documentId, 54);
        $this->manager();

        $first = $this->decoded($this->get('/accounting_einvoicing'));

        $this->assertSame(50, $this->occurrencesOf($first, 'data-document="'));
        $this->assertStringContainsString('1 to 50 of 55', $first);
        $this->assertStringContainsString('rel="next"', $first);
        $this->assertStringNotContainsString('rel="prev"', $first);
        $this->assertStringContainsString('documents_offset=50#einvoice_documents_title', $first);

        $second = $this->decoded($this->get('/accounting_einvoicing?documents_offset=50'));

        $this->assertSame(5, $this->occurrencesOf($second, 'data-document="'));
        $this->assertStringContainsString('51 to 55 of 55', $second);
        $this->assertStringContainsString('rel="prev"', $second);
        $this->assertStringNotContainsString('rel="next"', $second);
        $this->assertStringContainsString('documents_offset=0#einvoice_documents_title', $second);

        foreach (['500', '55', '51'] as $outOfRange) {
            $this->assertStringContainsString('51 to 55 of 55', $this->get('/accounting_einvoicing?documents_offset=' . $outOfRange)->response()->getBody(), $outOfRange);
        }

        foreach (['-5', '-60', 'abc', '3'] as $inRange) {
            $this->assertStringContainsString('1 to 50 of 55', $this->get('/accounting_einvoicing?documents_offset=' . $inRange)->response()->getBody(), $inRange);
        }
    }

    public function testGetIndex_ShowsNoPagerWhenEveryDocumentFitsOnOnePage(): void
    {
        $this->issued();
        $this->manager();

        $body = $this->decoded($this->get('/accounting_einvoicing'));

        $this->assertSame(1, $this->occurrencesOf($body, 'data-document="'));
        $this->assertStringNotContainsString('data-pager="documents_offset"', $body);
        $this->assertStringNotContainsString('rel="next"', $body);
    }

    public function testGetIndex_PagesTheCustomersWithoutIdentificationTwentyFiveAtATimeAndKeepsThePageOfTheDocumentsInTheLinks(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->insertCustomer('Page' . $i, 'Customer');
        }

        $this->manager();

        $total = (new EInvoiceReadiness())->customersWithoutIdentification(1)['total'];
        $first = $this->decoded($this->get('/accounting_einvoicing'));

        $this->assertGreaterThanOrEqual(27, $total);
        $this->assertSame(25, $this->occurrencesOf($first, 'data-customer="'));
        $this->assertStringContainsString('1 to 25 of ' . $total, $first);
        $this->assertStringContainsString('customers_offset=25#einvoice_customers_title', $first);

        $second = $this->decoded($this->get('/accounting_einvoicing?customers_offset=25&documents_offset=50'));

        $this->assertSame(min(25, $total - 25), $this->occurrencesOf($second, 'data-customer="'));
        $this->assertStringContainsString('26 to ' . min(50, $total) . ' of ' . $total, $second);
        $this->assertStringContainsString('rel="prev"', $second);
    }

    public function testGetIndex_OffersTheDebitNotesThatWaitAndTheDocumentsOfTheirCustomerInsteadOfAFieldForANumber(): void
    {
        $document = $this->issued();
        $noteId   = $this->debitNoteOf($this->employeeId);
        $other    = $this->anotherCustomer('Layla', 'Hassan');
        $foreign  = (new EInvoiceService())->issueSale($this->referenceSale($this->makeBusinessCustomer($other, '322222222222223', 'Other Co')), null, '', null)->documentId;

        $this->manager();

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('<select name="debit_note_id" id="debit_note_id"');
        $page->assertSee('<option value="' . $noteId . '" data-customer="' . $this->customerId . '">');
        $page->assertSee('<option value="' . $document . '" data-customer="' . $this->customerId . '">');
        $page->assertDontSee('type="number" min="1" name="debit_note_id"');
        $page->assertDontSee('einvoice_notes_none');
        $this->assertStringNotContainsString('<option value="' . $foreign . '" data-customer="' . $other . '">', $page->response()->getBody());
    }

    public function testGetIndex_SaysThatNoDebitNoteWaitsWhenEveryOneHasItsDocument(): void
    {
        $this->manager();

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('id="einvoice_notes_none"');
        $page->assertDontSee('id="debit_note_form"');
    }

    public function testGetIndex_OffersNoDebitNotesToWhoMayNotManage(): void
    {
        $this->debitNoteOf($this->employeeId);
        $this->reader();

        $page = $this->get('/accounting_einvoicing');

        $page->assertDontSee('id="debit_note_form"');
        $page->assertDontSee('id="einvoice_notes_none"');
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
        $other = $this->createBranch('B2');
        $this->branchLocation($other);
        $saleId = $this->invoiceInBranch($other, '115.00');

        $this->manager([1]);

        $result = $this->jsonOf($this->post('/accounting_einvoicing/issue/' . $saleId));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
    }

    public function testPostIssue_RefusesTheSaleOfAnotherEmployeeToSomeoneWhoSeesTheirOwnRecordsAndIssuesTheirOwn(): void
    {
        $foreign = $this->referenceSale();
        $own     = $this->ownRecordsManager();
        $mine    = $this->ownedBy($this->referenceSale(), $own);

        $refused = $this->jsonOf($this->post('/accounting_einvoicing/issue/' . $foreign));

        $this->assertFalse($refused['success']);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
        $this->assertTrue($this->jsonOf($this->post('/accounting_einvoicing/issue/' . $mine))['success']);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
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

    public function testPostIssueDebitNote_SaysInWordsThatTheDocumentIsOfAnotherCustomer(): void
    {
        $invoice = (new EInvoiceService())->issueSale($this->referenceSale($this->makeBusinessCustomer($this->anotherCustomer('Layla', 'Hassan'), '322222222222223', 'Other Co')), null, '', null);
        $noteId  = $this->debitNoteOf($this->employeeId);

        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/issue_debit_note/' . $noteId, ['original_document_id' => (string) $invoice->documentId, 'reason' => 'reason']));

        $this->assertFalse($result['success']);
        $this->assertSame('The debit note is not of the customer of the document ' . $invoice->number . ': a debit note refers to a document of its own customer.', $result['message']);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
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

    public function testGetXml_IsNotFoundForTheDocumentOfAnotherEmployeeToSomeoneWhoSeesTheirOwnRecordsOnly(): void
    {
        $foreign = $this->issued();
        $own     = $this->ownRecordsManager();
        $mine    = (new EInvoiceService())->issueSale($this->ownedBy($this->referenceSale(), $own), null, '', null)->documentId;

        $this->get('/accounting_einvoicing/xml/' . $mine)->assertStatus(200);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_einvoicing/xml/' . $foreign);
    }

    public function testSalePanel_ShowsTheStatusOnTheReceiptPage(): void
    {
        $saleId = $this->referenceSale();

        $this->actAs($this->employeeWithGrants(['reports_sales', 'sales', 'sales_stock', AccountingGuard::EINVOICING_MANAGE]));

        $page = $this->get('/sales/receipt/' . $saleId);

        $page->assertSee('id="einvoice_panel" data-state="not_generated"');
        $page->assertSee('id="einvoice_issue"');
    }

    public function testSalePanel_ShowsTheStatusOnTheTaxInvoicePage(): void
    {
        $saleId = $this->referenceSale();
        $before = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings = [...$before, 'invoice_type' => 'tax_invoice'];

        try {
            $this->actAs($this->employeeWithGrants(['reports_sales', 'sales', 'sales_stock', AccountingGuard::EINVOICING_MANAGE]));

            $page = $this->get('/sales/invoice/' . $saleId);

            $this->assertStringContainsString('sales/pdf/' . $saleId . '/tax_invoice', $this->decoded($page));
            $page->assertSee('id="einvoice_panel" data-state="not_generated"');
            $page->assertSee('id="einvoice_issue"');
        } finally {
            config(OSPOS::class)->settings = $before;
        }
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

    private function registeredBusiness(): void
    {
        $this->accountingDb()->table('company_tax_profile')->where('company_id', 1)->update(['vat_registered' => 1, 'vat_number' => self::SELLER_VAT]);
    }

    public function testGetIndex_ShowsTheTaxIdentityCardWithTheModeOff(): void
    {
        $this->manager();

        $page = $this->get('/accounting_einvoicing');

        $page->assertSee('id="tax_identity_card"');
        $page->assertSee('Not specified');
        $page->assertSee('id="tax_identity_mode"');
        $page->assertDontSee('id="saudi_mode_form"');
    }

    public function testGetIndex_OffersTheSwitchToAHolderOfBothPermissions(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_einvoicing', AccountingGuard::EINVOICING_MANAGE, CompanyTaxProfile::CONFIG_PERMISSION]));

        $this->get('/accounting_einvoicing')->assertSee('id="saudi_mode_enable"');
    }

    public function testGetIndex_WarnsOfATaxInvoiceTitleForABusinessThatIsNotRegistered(): void
    {
        $this->accountingDb()->table('company_tax_profile')->where('company_id', 1)->update(['vat_registered' => 0]);
        $saved = (string) ($this->accountingDb()->table('app_config')->where('key', 'invoice_type')->get()->getRow()->value ?? 'invoice');
        $this->setConfigValue('invoice_type', 'tax_invoice');

        try {
            $this->reader();

            $this->get('/accounting_einvoicing')->assertSee('id="tax_identity_title_warning"');
        } finally {
            $this->setConfigValue('invoice_type', $saved);
        }
    }

    public function testPostSaudiMode_EnablesForAHolderOfBothPermissions(): void
    {
        $this->registeredBusiness();
        $this->actAs($this->employeeWithGrants(['accounting_einvoicing', AccountingGuard::EINVOICING_MANAGE, CompanyTaxProfile::CONFIG_PERMISSION]));

        $result = $this->jsonOf($this->post('/accounting_einvoicing/saudi_mode', ['action' => 'enable', 'effective_at' => '']));

        $this->assertTrue($result['success']);
        $this->assertTrue((new CompanyTaxProfile())->current()->saudiMode);
    }

    public function testPostSaudiMode_RefusesWithoutTheSettingsPermission(): void
    {
        $this->registeredBusiness();
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_einvoicing/saudi_mode', ['action' => 'enable', 'effective_at' => '']));

        $this->assertFalse($result['success']);
        $this->assertFalse((new CompanyTaxProfile())->current()->saudiMode);
    }

    public function testPostSaudiMode_RefusesABusinessThatIsNotRegistered(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_einvoicing', AccountingGuard::EINVOICING_MANAGE, CompanyTaxProfile::CONFIG_PERMISSION]));

        $result = $this->jsonOf($this->post('/accounting_einvoicing/saudi_mode', ['action' => 'enable', 'effective_at' => '']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Tax_profile.error_mode_needs_registered'), $result['message']);
    }

    public function testPostSaudiMode_RejectsAnUnknownActionAndChangesNothing(): void
    {
        $this->registeredBusiness();
        $this->accountingDb()->table('company_tax_profile')->where('company_id', 1)->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2026-01-01 00:00:00']);
        $this->actAs($this->employeeWithGrants(['accounting_einvoicing', AccountingGuard::EINVOICING_MANAGE, CompanyTaxProfile::CONFIG_PERMISSION]));

        $response = $this->post('/accounting_einvoicing/saudi_mode', ['action' => 'disabl']);

        $response->assertStatus(400);
        $this->assertTrue((new CompanyTaxProfile())->current()->saudiMode);
    }

    public function testGetIndex_OffersTheSwitchOffToTheOnboardHolderOnceDocumentsExist(): void
    {
        $this->registeredBusiness();
        $this->issued();
        $this->accountingDb()->table('company_tax_profile')->where('company_id', 1)->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2026-01-01 00:00:00', 'ksa_mode_watermark' => 0]);

        $this->actAs($this->employeeWithGrants(['accounting_einvoicing', AccountingGuard::EINVOICING_MANAGE, CompanyTaxProfile::CONFIG_PERMISSION]));
        $this->get('/accounting_einvoicing')->assertDontSee('id="saudi_mode_disable"');

        $this->actAs($this->employeeWithGrants(['accounting_einvoicing', AccountingGuard::EINVOICING_ONBOARD]));
        $this->get('/accounting_einvoicing')->assertSee('id="saudi_mode_disable"');
    }
}
