<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalDescriptions;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\ReceivingDocument;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\ReturnOverride;
use App\Libraries\Accounting\SaleDocument;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class JournalDescriptionsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private JournalDescriptions $descriptions;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->descriptions = new JournalDescriptions();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('app_config')->whereIn('key', ['accounting_journal_description_sales', 'accounting_journal_description_purchases'])->delete();
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function draft(string $description = 'Posted by the application'): JournalDraft
    {
        return new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '10.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00')],
            sourceType: 'sale',
            sourceId: 7,
            revision: 1,
            description: $description,
        );
    }

    private function sale(int $type = SALE_TYPE_INVOICE, ?int $customerId = null, bool $refundInvoice = false, bool $override = false, int $saleId = 7): SaleDocument
    {
        return new SaleDocument(
            saleId: $saleId,
            date: '2026-10-21',
            saleType: $type,
            status: COMPLETED,
            customerId: $customerId,
            branchId: 1,
            delivered: true,
            returnOfSaleId: null,
            lines: [],
            payments: [],
            returnOverride: $override,
            refundInvoice: $refundInvoice,
        );
    }

    private function purchase(ReceivingType $type = ReceivingType::Receive, ?int $supplierId = null, int $receivingId = 9): ReceivingDocument
    {
        return new ReceivingDocument(
            receivingId: $receivingId,
            date: '2026-10-22',
            type: $type,
            supplierId: $supplierId,
            branchId: 1,
            paymentType: null,
            returnOfReceivingId: null,
            lines: [],
        );
    }

    public function testOfSaleKeepsTheDraftAsItIsWhenThereIsNoTemplate(): void
    {
        $draft = $this->draft();

        $this->assertSame($draft, $this->descriptions->ofSale($draft, $this->sale()));
    }

    public function testOfSaleFillsEveryPlaceholderOfTheTemplateOfSales(): void
    {
        $customerId = $this->insertCustomer('Omar', 'Khaled');

        $this->setConfigValue('accounting_journal_description_sales', '{type} {number} for {party} on {date}');

        $text = $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_INVOICE, $customerId))->description;

        $this->assertSame(lang('Accounting.template_type_invoice') . ' 7 for Omar Khaled on 2026-10-21', $text);
    }

    public function testOfSaleNamesThePartyAsNothingForASaleWithoutACustomer(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', 'Sale {number} [{party}]');

        $this->assertSame('Sale 7 []', $this->descriptions->ofSale($this->draft(), $this->sale())->description);
    }

    public function testOfSaleNamesTheTypeOfEveryKindOfSale(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', '{type}');

        $this->assertSame(lang('Accounting.template_type_invoice'), $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_INVOICE))->description);
        $this->assertSame(lang('Accounting.template_type_sale'), $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_POS))->description);
        $this->assertSame(lang('Accounting.template_type_credit_note'), $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_RETURN))->description);
        $this->assertSame(lang('Accounting.template_type_refund_invoice'), $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_RETURN, refundInvoice: true))->description);
    }

    public function testOfSaleNumbersACreditNoteAndARefundInvoiceByTheirOwnSequences(): void
    {
        $numbering = new DocumentNumbering();
        $numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-', 'padding' => 4]);
        $numbering->configure(DocumentType::RefundInvoice, ['enabled' => true, 'prefix' => 'RI-', 'padding' => 4]);
        $numbering->assign(DocumentType::CreditNote, 'sales', 21);
        $numbering->assign(DocumentType::RefundInvoice, 'sales', 22);

        $this->setConfigValue('accounting_journal_description_sales', '{number}');

        $this->assertSame('CN-0001', $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_RETURN, saleId: 21))->description);
        $this->assertSame('RI-0001', $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_RETURN, refundInvoice: true, saleId: 22))->description);
    }

    public function testOfSaleKeepsTheMarkOfAReturnWithoutASale(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', 'Doc {number}');

        $label = (new DocumentNumbering())->label(DocumentType::CreditNote, 'sales', 7);

        $this->assertSame(ReturnOverride::describe('Doc ' . $label, true), $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_RETURN, override: true))->description);
        $this->assertSame('Doc ' . $label, $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_RETURN))->description);
    }

    public function testOfSaleTakesTheInvoiceNumberOfASavedInvoiceForTheNumber(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', '{number}');
        $this->accountingDb()->table('sales')->insert(['sale_id' => 987654, 'sale_time' => '2026-10-21 10:00:00', 'employee_id' => 1, 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_INVOICE, 'invoice_number' => 'INV-55', 'branch_id' => 1]);

        try {
            $this->assertSame('INV-55', $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_INVOICE, saleId: 987654))->description);
        } finally {
            $this->accountingDb()->table('sales')->where('sale_id', 987654)->delete();
        }
    }

    public function testOfSaleTakesTheTextOfTheTemplateWithoutMarkupOrControlCharactersFromTheNameOfTheCustomer(): void
    {
        $customerId = $this->insertCustomer('<b>Omar</b>', "Khaled\n");

        $this->setConfigValue('accounting_journal_description_sales', 'For {party}');

        $this->assertSame('For Omar Khaled', $this->descriptions->ofSale($this->draft(), $this->sale(SALE_TYPE_INVOICE, $customerId))->description);
    }

    public function testOfPurchaseKeepsTheDraftAsItIsWhenThereIsNoTemplate(): void
    {
        $draft = $this->draft();

        $this->assertSame($draft, $this->descriptions->ofPurchase($draft, $this->purchase()));
    }

    public function testOfPurchaseFillsEveryPlaceholderOfTheTemplateOfPurchasesWithTheCompanyOfTheSupplier(): void
    {
        $supplierId = $this->insertSupplier('Gulf Trading');

        $this->setConfigValue('accounting_journal_description_purchases', '{type} {number} from {party} on {date}');

        $text = $this->descriptions->ofPurchase($this->draft(), $this->purchase(supplierId: $supplierId))->description;

        $this->assertSame(lang('Accounting.template_type_purchase') . ' ' . (new DocumentNumbering())->label(DocumentType::Purchase, 'receivings', 9) . ' from Gulf Trading on 2026-10-22', $text);
    }

    public function testOfPurchaseFallsBackToThePersonNameOfASupplierWithoutACompany(): void
    {
        $supplierId = $this->insertSupplier('', 'Sara', 'Nasser');

        $this->setConfigValue('accounting_journal_description_purchases', 'From {party}');

        $this->assertSame('From Sara Nasser', $this->descriptions->ofPurchase($this->draft(), $this->purchase(supplierId: $supplierId))->description);
    }

    public function testOfPurchaseNamesThePartyAsNothingForAPurchaseWithoutASupplier(): void
    {
        $this->setConfigValue('accounting_journal_description_purchases', 'From [{party}]');

        $this->assertSame('From []', $this->descriptions->ofPurchase($this->draft(), $this->purchase())->description);
    }

    public function testOfPurchaseNamesAPurchaseReturnByTheTypeAndTheNumberOfItsOwnKind(): void
    {
        $numbering = new DocumentNumbering();
        $numbering->configure(DocumentType::DebitNote, ['enabled' => true, 'prefix' => 'DN-', 'padding' => 3]);
        $numbering->configure(DocumentType::Purchase, ['enabled' => true, 'prefix' => 'PU-', 'padding' => 3]);
        $numbering->assign(DocumentType::DebitNote, 'receivings', 31);
        $numbering->assign(DocumentType::Purchase, 'receivings', 32);

        $this->setConfigValue('accounting_journal_description_purchases', '{type} {number}');

        $this->assertSame(lang('Accounting.template_type_purchase_return') . ' DN-001', $this->descriptions->ofPurchase($this->draft(), $this->purchase(ReceivingType::Return, receivingId: 31))->description);
        $this->assertSame(lang('Accounting.template_type_purchase') . ' PU-001', $this->descriptions->ofPurchase($this->draft(), $this->purchase(ReceivingType::Receive, receivingId: 32))->description);
    }

    public function testOfPurchaseIgnoresTheTemplateOfSalesAndTheOtherWayAround(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', 'Sales only');

        $draft = $this->draft();

        $this->assertSame($draft, $this->descriptions->ofPurchase($draft, $this->purchase()));

        $this->setConfigValue('accounting_journal_description_sales', '');
        $this->setConfigValue('accounting_journal_description_purchases', 'Purchases only');

        $this->assertSame($draft, $this->descriptions->ofSale($draft, $this->sale()));
    }

    public function testOfSaleKeepsTheDraftWhenTheTemplateStoredIsNotValid(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', '<b>{number}</b>');

        $draft = $this->draft();

        $this->assertSame($draft, $this->descriptions->ofSale($draft, $this->sale()));
    }
}
