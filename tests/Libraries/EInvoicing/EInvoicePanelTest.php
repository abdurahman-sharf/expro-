<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\EInvoicePanel;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\EInvoicing\EInvoiceSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the page of an invoice says of its electronic document.
 */
class EInvoicePanelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use EInvoiceFixtureTrait;
    use EInvoiceSaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private EInvoicePanel $panel;
    private EInvoiceService $service;
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
        $this->configureEInvoicing();

        $this->panel   = new EInvoicePanel();
        $this->service = new EInvoiceService();
        $this->clerk   = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->clearEInvoices();
        $this->restoreEInvoicingConfig();
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();
        $this->removeTemporaryDirectories();
        $this->stopSelling();

        parent::tearDown();
    }

    public function testForSale_ShowsNothingWhileTheLayerIsOff(): void
    {
        $saleId = $this->referenceSale();

        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);

        $this->assertNull($this->panel->forSale($saleId, true));
    }

    public function testForSale_ShowsNothingForASaleThatDoesNotExistOrIsNotACompletedInvoiceOrReturn(): void
    {
        $cycle = $this->referenceInvoice();
        $quote = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_QUOTE, -1, false, null, SUSPENDED);

        $this->assertNull($this->panel->forSale(987654321, true));
        $this->assertNull($this->panel->forSale($quote, true));
    }

    public function testForSale_SaysNotGeneratedAndOffersToIssueToSomeoneWhoMay(): void
    {
        $saleId = $this->referenceSale();
        $panel  = $this->panel->forSale($saleId, true);

        $this->assertSame('not_generated', $panel['state']);
        $this->assertSame('not_submitted', $panel['submission']);
        $this->assertNull($panel['document']);
        $this->assertTrue($panel['can_issue']);
        $this->assertFalse($panel['is_return']);
        $this->assertSame('simplified', $panel['default_subtype']);
        $this->assertFalse($this->panel->forSale($saleId, false)['can_issue']);
    }

    public function testForSale_OffersTheTaxInvoiceToACustomerThatIsABusiness(): void
    {
        $saleId = $this->referenceSale($this->makeBusinessCustomer());

        $this->assertSame('standard', $this->panel->forSale($saleId, true)['default_subtype']);
    }

    public function testForSale_SaysGeneratedOnceTheDocumentIsIssuedAndStillNotSubmitted(): void
    {
        $saleId = $this->referenceSale();
        $this->service->issueSale($saleId, null, '', $this->clerk);

        $panel = $this->panel->forSale($saleId, true);

        $this->assertSame('generated', $panel['state']);
        $this->assertSame('not_submitted', $panel['submission']);
        $this->assertSame(1, $panel['document']->icv);
        $this->assertFalse($panel['can_issue']);
        $this->assertTrue($panel['in_sync']);
    }

    public function testForSale_SaysSignedWhenTheDocumentCarriesTheStamp(): void
    {
        $this->skipWithoutTheCurve();

        $pair = $this->generateKeyPair();

        $this->configureEInvoicing(['einvoice_key_path' => $pair['key'], 'einvoice_certificate_path' => $pair['certificate']]);

        $saleId = $this->counterSale();
        $this->service->issueSale($saleId, null, '', $this->clerk);

        $this->assertSame('signed', $this->panel->forSale($saleId, true)['state']);
    }

    public function testForSale_WarnsThatTheBooksChangedAfterTheDocumentWasIssued(): void
    {
        $saleId = $this->referenceSale();
        $this->service->issueSale($saleId, null, '', $this->clerk);

        $journal = $this->standing($saleId, 'revenue')[0];
        $this->accountingDb()->table('accounting_journal_lines')->where(['journal_id' => $journal['id'], 'account_id' => $this->customerAccount()])->update(['debit' => '900.00']);

        $this->assertFalse($this->panel->forSale($saleId, true)['in_sync']);
    }

    public function testForSale_SaysAReturnNeedsItsInvoiceToBeIssuedFirst(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $return  = $this->perfumeReturn($invoice, $cycle['perfume']);

        $this->assertTrue($this->panel->forSale($return, true)['is_return']);
        $this->assertFalse($this->panel->forSale($return, true)['original_issued']);

        $this->service->issueSale($invoice, null, '', $this->clerk);

        $this->assertTrue($this->panel->forSale($return, true)['original_issued']);
    }
}
