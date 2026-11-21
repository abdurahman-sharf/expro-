<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SaleLoader;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\EInvoicing\EInvoiceBuilder;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use App\Libraries\EInvoicing\OriginalReference;
use App\Libraries\Tax\LineVat;
use App\Libraries\Tax\VatCategoryTotal;
use App\Libraries\Tax\VatSnapshot;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The document of a sale: the figures are those of the books, and a sale the standard cannot be written from
 * is refused with the rule instead of being adjusted.
 */
class EInvoiceBuilderTest extends CIUnitTestCase
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
    private EInvoiceBuilder $builder;

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

        $this->builder = new EInvoiceBuilder();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('sales_items_vat')->truncate();
        $this->accountingDb()->table('sales_vat_totals')->truncate();
        $this->clearEInvoices();
        $this->restoreEInvoicingConfig();
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();
        $this->accountingDb()->table('app_config')->where('key', 'accounting_debit_note')->delete();
        $this->stopSelling();

        parent::tearDown();
    }

    private function refusal(callable $action): EInvoiceException
    {
        try {
            $action();
        } catch (EInvoiceException $failure) {
            return $failure;
        }

        $this->fail('The document was not refused.');
    }

    public function testFromSale_BuildsTheReferenceInvoiceWithTheFiguresOfTheBooks(): void
    {
        $saleId   = $this->referenceSale();
        $document = $this->builder->fromSale($saleId);

        $this->assertSame(EInvoiceKind::Invoice, $document->kind);
        $this->assertSame(EInvoiceSubtype::Simplified, $document->subtype);
        $this->assertCount(2, $document->lines);

        $cream = $document->lines[0];

        $this->assertSame('Cream', $cream->name);
        $this->assertSame('4.00', $cream->quantity);
        $this->assertSame('75.0000', $cream->priceNet);
        $this->assertSame('30.00', $cream->allowance);
        $this->assertSame('270.00', $cream->netAmount);
        $this->assertSame('S', $cream->vatCategory);
        $this->assertSame('15.00', $cream->vatRate);
        $this->assertSame('40.50', $cream->vatAmount);
        $this->assertSame('310.50', $cream->amountWithVat);

        $perfume = $document->lines[1];

        $this->assertSame('0.00', $perfume->allowance);
        $this->assertSame('440.00', $perfume->netAmount);
        $this->assertSame('66.00', $perfume->vatAmount);

        $this->assertSame('710.00', $document->lineExtension);
        $this->assertSame('710.00', $document->taxExclusive);
        $this->assertSame('106.50', $document->taxAmount);
        $this->assertSame('816.50', $document->taxInclusive);
        $this->assertCount(1, $document->taxSubtotals);
        $this->assertSame('710.00', $document->taxSubtotals[0]->taxable);
        $this->assertSame('106.50', $document->taxSubtotals[0]->taxAmount);
    }

    public function testFromSale_StatesTheTotalsTheSalePostingBuilderPutsInTheJournal(): void
    {
        $saleId   = $this->referenceSale();
        $document = $this->builder->fromSale($saleId);
        $sale     = (new SaleLoader())->load($saleId);
        $figures  = SaleAmounts::figures($sale, totals_decimals());
        $draft    = SalePostingBuilder::forDatabase($this->accountingDb())->invoice($sale, 1);
        $debit    = '0';
        $credit   = '0';

        foreach ($draft->lines as $line) {
            $debit  = bcadd($debit, $line->debit ?? '0', 4);
            $credit = bcadd($credit, $line->credit ?? '0', 4);
        }

        $this->assertSame($document->taxInclusive, bcadd($figures['total'], '0', 2));
        $this->assertSame($document->taxInclusive, bcadd($debit, '0', 2));
        $this->assertSame($document->taxInclusive, bcadd($credit, '0', 2));
        $this->assertSame($document->taxAmount, bcadd($figures['tax'], '0', 2));
        $this->assertSame($document->taxExclusive, bcadd($figures['revenue'], '0', 2));
    }

    public function testFromSale_FoldsTheDiscountOnTheWholeInvoiceIntoTheAllowanceOfTheLines(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $document = $this->builder->fromSale($saleId);

        $this->assertSame('639.00', $document->lineExtension);
        $this->assertSame('95.85', $document->taxAmount);
        $this->assertSame('734.85', $document->taxInclusive);
        $this->assertSame('57.00', $document->lines[0]->allowance);
        $this->assertSame('243.00', $document->lines[0]->netAmount);
        $this->assertSame('396.00', $document->lines[1]->netAmount);
    }

    public function testFromSale_WritesTheCreditNoteOfAReturnWithPositiveAmountsAndTheReferenceToTheInvoice(): void
    {
        $cycle    = $this->referenceInvoice();
        $invoice  = $this->save($cycle['items'], $cycle['taxes']);
        $return   = $this->perfumeReturn($invoice, $cycle['perfume']);
        $original = new OriginalReference(7, 'INV-7', EInvoiceSubtype::Simplified);

        $this->nameItem($cycle['perfume'], 'Perfume');

        $document = $this->builder->fromSale($return, null, 'In case of goods or services refund.', $original);

        $this->assertSame(EInvoiceKind::CreditNote, $document->kind);
        $this->assertSame(EInvoiceSubtype::Simplified, $document->subtype);
        $this->assertSame('INV-7', $document->billingReference);
        $this->assertSame('In case of goods or services refund.', $document->reason);
        $this->assertSame('253.00', $document->taxInclusive);
        $this->assertSame('33.00', $document->taxAmount);
        $this->assertSame('220.00', $document->taxExclusive);
        $this->assertSame('1.00', $document->lines[0]->quantity);
        $this->assertSame('220.00', $document->lines[0]->netAmount);
        $this->assertSame(['10'], $document->paymentMeansCodes);
        $this->assertStringStartsWith('POS ', $document->number);
    }

    public function testFromSale_RefusesACreditNoteThatNamesNoReasonAndNoOriginal(): void
    {
        $cycle  = $this->referenceInvoice();
        $return = $this->creditNoteOf($this->save($cycle['items'], $cycle['taxes']), '100.00');

        $this->assertSame(EInvoiceError::OriginalNotIssued, $this->refusal(fn () => $this->builder->fromSale($return, null, 'a reason'))->error);
        $this->assertSame(EInvoiceError::ReasonRequired, $this->refusal(fn () => $this->builder->fromSale($return, null, '  ', new OriginalReference(1, 'INV-1', EInvoiceSubtype::Simplified)))->error);
        $this->assertSame(EInvoiceError::ReasonTooLong, $this->refusal(fn () => $this->builder->fromSale($return, null, str_repeat('x', 1001), new OriginalReference(1, 'INV-1', EInvoiceSubtype::Simplified)))->error);
    }

    public function testFromSale_ChoosesSimplifiedForACounterSaleAndStandardForACustomerWithATaxNumber(): void
    {
        $counter = $this->counterSale();
        $company = $this->referenceSale($this->makeBusinessCustomer());

        $this->assertSame(EInvoiceSubtype::Simplified, $this->builder->defaultSubtype($counter));
        $this->assertSame('Cash customer', $this->builder->fromSale($counter)->buyer->name);
        $this->assertSame(EInvoiceSubtype::Standard, $this->builder->defaultSubtype($company));
        $this->assertSame(EInvoiceSubtype::Standard, $this->builder->fromSale($company)->subtype);
        $this->assertSame('Buyer Co', $this->builder->fromSale($company)->buyer->name);
        $this->assertSame('311111111111113', $this->builder->fromSale($company)->buyer->vatNumber);
    }

    public function testFromSale_TreatsAGovernmentEntityAsABusinessWhateverItsTaxNumber(): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['is_government_entity' => 1]);

        $saleId = $this->referenceSale($this->customerId);

        $this->assertSame(EInvoiceSubtype::Standard, $this->builder->defaultSubtype($saleId));
    }

    public function testFromSale_LetsTheIssuerChooseTheSubtypeOfAnInvoice(): void
    {
        $saleId = $this->referenceSale($this->makeBusinessCustomer());

        $this->assertSame(EInvoiceSubtype::Simplified, $this->builder->fromSale($saleId, EInvoiceSubtype::Simplified)->subtype);
    }

    public function testFromSale_RefusesATaxInvoiceToABusinessWithNoTaxNumberAndNoIdentification(): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['customer_type' => 'commercial']);

        $saleId = $this->referenceSale($this->customerId);

        $this->assertSame(EInvoiceError::BuyerIdentificationMissing, $this->refusal(fn () => $this->builder->fromSale($saleId))->error);
    }

    public function testFromSale_RefusesATaxNumberOfTheBuyerThatIsNotFifteenDigitsBeginningAndEndingWithThree(): void
    {
        $saleId = $this->referenceSale($this->makeBusinessCustomer(null, '12345'));

        $this->assertSame(EInvoiceError::BuyerVatInvalid, $this->refusal(fn () => $this->builder->fromSale($saleId))->error);
    }

    public function testFromSale_RefusesATaxInvoiceWhoseBuyerHasNoNationalAddress(): void
    {
        $this->makeBusinessCustomer();
        $this->accountingDb()->table('customer_einvoice_parties')->where('person_id', $this->customerId)->update(['district' => '']);

        $saleId = $this->referenceSale($this->customerId);

        $this->assertSame(EInvoiceError::BuyerAddressIncomplete, $this->refusal(fn () => $this->builder->fromSale($saleId))->error);
    }

    public function testFromSale_UsesTheIdentificationOfABuyerWithNoTaxNumber(): void
    {
        $this->makeBusinessCustomer(null, '', 'Mohammed Co');
        $this->accountingDb()->table('customer_einvoice_parties')->where('person_id', $this->customerId)->update(['identification_type' => 'NAT', 'identification_number' => '1098765432']);
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['customer_type' => 'commercial']);

        $document = $this->builder->fromSale($this->referenceSale($this->customerId));

        $this->assertSame('NAT', $document->buyer->identificationType?->value);
        $this->assertSame('1098765432', $document->buyer->identificationNumber);
        $this->assertSame('', $document->buyer->vatNumber);
    }

    public function testFromSale_GivesTheBuyerOfASimplifiedInvoiceNoAddressWhenItIsNotComplete(): void
    {
        $this->accountingDb()->table('customer_einvoice_parties')->replace(['person_id' => $this->customerId, 'street' => 'Only a street']);

        $document = $this->builder->fromSale($this->referenceSale($this->customerId));

        $this->assertSame(EInvoiceSubtype::Simplified, $document->subtype);
        $this->assertNull($document->buyer->address);
    }

    public function testFromSale_RefusesTheDocumentWhileTheSellerIsIncomplete(): void
    {
        $saleId = $this->referenceSale();

        foreach ([
            ['tax_id', '12345', EInvoiceError::SellerVatInvalid],
            ['tax_id', '', EInvoiceError::SellerVatInvalid],
            ['company', '', EInvoiceError::SellerNameMissing],
            ['einvoice_seller_identification_number', '', EInvoiceError::SellerIdentificationMissing],
            ['einvoice_seller_identification_type', 'NAT', EInvoiceError::SellerIdentificationMissing],
            ['einvoice_seller_street', '', EInvoiceError::SellerAddressIncomplete],
            ['einvoice_seller_building_number', '', EInvoiceError::SellerAddressIncomplete],
            ['einvoice_seller_district', '', EInvoiceError::SellerAddressIncomplete],
            ['einvoice_seller_city', '', EInvoiceError::SellerAddressIncomplete],
            ['einvoice_seller_postal_code', '', EInvoiceError::SellerAddressIncomplete],
        ] as [$key, $value, $error]) {
            $this->configureEInvoicing([$key => $value]);

            $this->assertSame($error, $this->refusal(fn () => $this->builder->fromSale($saleId))->error, $key . '=' . $value);

            $this->configureEInvoicing();
        }
    }

    public function testFromSale_RefusesALineThatCarriesTwoTaxes(): void
    {
        $item  = $this->item('0', false);
        $first = $this->taxRow($item, 0, 'VAT', '15.0000', 1, '15.00');
        $other = $this->taxRow($item, 0, 'Levy', '2.0000', 1, '2.00');

        $saleId = $this->save([$this->line($item, 0, '1', '100')], [$first, $other]);

        $this->assertSame(EInvoiceError::LineTaxAmbiguous, $this->refusal(fn () => $this->builder->fromSale($saleId))->error);
    }

    public function testFromSale_RefusesALineWithoutTaxWhileTheSettingsDoNotSayWhatItIs(): void
    {
        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '100')]);

        $this->assertSame(EInvoiceError::ExemptionNotConfigured, $this->refusal(fn () => $this->builder->fromSale($saleId))->error);
    }

    public function testFromSale_ClassifiesALineWithoutTaxAsTheSettingsSayWithItsReasonCode(): void
    {
        $this->configureEInvoicing(['einvoice_exempt_category' => 'Z', 'einvoice_exempt_reason_code' => 'VATEX-SA-32', 'einvoice_exempt_reason_text' => 'Export of goods']);

        $item     = $this->item('0', false);
        $document = $this->builder->fromSale($this->save([$this->line($item, 0, '2', '50')]));

        $this->assertSame('Z', $document->lines[0]->vatCategory);
        $this->assertSame('0.00', $document->lines[0]->vatRate);
        $this->assertSame('100.00', $document->taxInclusive);
        $this->assertSame('Z', $document->taxSubtotals[0]->category);
        $this->assertSame('VATEX-SA-32', $document->taxSubtotals[0]->exemptionCode);
        $this->assertSame('Export of goods', $document->taxSubtotals[0]->exemptionText);
    }

    public function testFromSale_WritesOneSubtotalForEachCategoryAndRate(): void
    {
        $this->configureEInvoicing(['einvoice_exempt_category' => 'E', 'einvoice_exempt_reason_code' => 'VATEX-SA-29']);

        $taxed  = $this->item('0', false);
        $exempt = $this->item('0', false);

        $document = $this->builder->fromSale($this->save([$this->line($taxed, 0, '1', '100'), $this->line($exempt, 1, '1', '40')], [$this->tax($taxed, 0, '15.00')]));

        $this->assertCount(2, $document->taxSubtotals);
        $this->assertSame('140.00', $document->lineExtension);
        $this->assertSame('15.00', $document->taxAmount);
        $this->assertSame('155.00', $document->taxInclusive);
        $this->assertSame(['S', 'E'], array_map(static fn ($subtotal): string => $subtotal->category, $document->taxSubtotals));
    }

    public function testFromSale_FoldsTheDiscountOfALineWhoseTaxIsInsideItsPriceIntoItsPrice(): void
    {
        $item = $this->item('0', false);
        $tax  = $this->taxRow($item, 0, 'VAT', '15.0000', 0, '13.04');

        $document = $this->builder->fromSale($this->save([$this->line($item, 0, '1', '100')], [$tax]));

        $this->assertSame('86.96', $document->lines[0]->netAmount);
        $this->assertSame('86.9600', $document->lines[0]->priceNet);
        $this->assertSame('0.00', $document->lines[0]->allowance);
        $this->assertSame('13.04', $document->lines[0]->vatAmount);
        $this->assertSame('100.00', $document->taxInclusive);
    }

    public function testFromSale_RefusesATaxTheBooksDoNotHoldEvenWhenTheTotalTies(): void
    {
        $sale    = (new SaleLoader())->load($this->referenceSale());
        $figures = SaleAmounts::figures($sale, totals_decimals());
        $rules   = ['subtotals' => [], 'lineExtension' => '0.00', 'tax' => bcadd(SaleAmounts::round($figures['tax'], 2), '0.01', 2), 'total' => bcadd($figures['total'], '0', 2)];
        $tie     = new ReflectionMethod($this->builder, 'tieToTheBooks');

        try {
            $tie->invoke($this->builder, $sale, $rules);
            $this->fail('A tax the books do not hold was accepted');
        } catch (EInvoiceException $refusal) {
            $this->assertSame(EInvoiceError::TotalsDoNotTie, $refusal->error);
            $this->assertSame('tax', $refusal->context['rule']);
        }

        $rules['tax'] = SaleAmounts::round($figures['tax'], 2);

        $this->assertSame('0.00', $tie->invoke($this->builder, $sale, $rules), 'a sale saved without its VAT worked out once has no rounding amount');
    }

    public function testFromSale_RefusesATotalThatTheStandardCannotWriteTheWayTheBooksHaveIt(): void
    {
        $item   = $this->item('0', false);
        $other  = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '0.333', '1'), $this->line($other, 1, '0.333', '1')], [$this->tax($item, 0, '0.0500'), $this->tax($other, 1, '0.0500')]);

        $refusal = $this->refusal(fn () => $this->builder->fromSale($saleId));

        $this->assertSame(EInvoiceError::TotalsDoNotTie, $refusal->error);
        $this->assertSame('total', $refusal->context['rule']);
        $this->assertSame('0.77', $refusal->context['expected']);
        $this->assertSame('0.76', $refusal->context['actual']);
    }

    public function testFromSale_RefusesASaleInAForeignCurrency(): void
    {
        $saleId = $this->referenceSale();

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['currency_code' => 'USD', 'currency_rate' => '3.75']);

        $this->assertSame(EInvoiceError::ForeignCurrency, $this->refusal(fn () => $this->builder->fromSale($saleId))->error);
    }

    public function testFromSale_RefusesASaleWithShippingOrAnAdjustmentInItsHeader(): void
    {
        $saleId = $this->referenceSale();

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['shipping_amount' => '10.00']);

        $this->assertSame(EInvoiceError::HeaderChargesUnsupported, $this->refusal(fn () => $this->builder->fromSale($saleId))->error);
    }

    public function testFromSale_RefusesAQuoteASaleThatDoesNotExistAndASaleThatIsNotCompleted(): void
    {
        $cycle = $this->referenceInvoice();
        $quote = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_QUOTE, -1, false, null, SUSPENDED);

        $this->assertSame(EInvoiceError::SourceNotIssuable, $this->refusal(fn () => $this->builder->fromSale($quote))->error);
        $this->assertSame(EInvoiceError::SourceNotFound, $this->refusal(fn () => $this->builder->fromSale(987654321))->error);
    }

    public function testFromSale_NamesThePaymentMeansOfTheCompletedPayments(): void
    {
        $document = $this->builder->fromSale($this->referenceSale(null, [$this->payment('Cash', '500.00'), $this->payment('Debit Card', '316.50')]));

        $this->assertSame(['10', '48'], $document->paymentMeansCodes);
    }

    public function testFromSale_UsesTheDayOfTheInvoiceAndTheTimeItWasSaved(): void
    {
        $this->grantInvoiceDate();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, $this->daysAgo(3));

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => '2026-03-05 21:15:42']);

        $document = $this->builder->fromSale($saleId);

        $this->assertSame($this->daysAgo(3), $document->issueDate);
        $this->assertSame($this->daysAgo(3), $document->supplyDate);
        $this->assertSame('21:15:42', $document->issueTime);
    }

    public function testFromDebitNote_WritesOneLineOfTheNoteWithItsTax(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $clerk  = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        $noteId = (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(2), 'amount' => '200', 'tax_percent' => '15', 'note' => 'Freight recovered'], $clerk);

        $document = $this->builder->fromDebitNote($noteId, 'Amendment of the supply value which is pre-agreed upon between the supplier and consumer', new OriginalReference(3, 'INV-3', EInvoiceSubtype::Simplified));

        $this->assertSame(EInvoiceKind::DebitNote, $document->kind);
        $this->assertSame(EInvoiceSubtype::Simplified, $document->subtype);
        $this->assertSame('INV-3', $document->billingReference);
        $this->assertSame('200.00', $document->lineExtension);
        $this->assertSame('30.00', $document->taxAmount);
        $this->assertSame('230.00', $document->taxInclusive);
        $this->assertSame('Freight recovered', $document->lines[0]->name);
        $this->assertSame('15.00', $document->lines[0]->vatRate);
        $this->assertSame($this->daysAgo(2), $document->issueDate);
    }

    public function testFromDebitNote_RefusesANoteThatWasTakenBackAndOneThatDoesNotExist(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $clerk  = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        $notes  = new SalesDebitNotes();
        $noteId = $notes->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(2), 'amount' => '200', 'tax_percent' => '15', 'note' => 'x'], $clerk);

        $notes->reverse($noteId, 'wrong amount', $clerk);

        $original = new OriginalReference(3, 'INV-3', EInvoiceSubtype::Simplified);

        $this->assertSame(EInvoiceError::SourceNotIssuable, $this->refusal(fn () => $this->builder->fromDebitNote($noteId, 'a reason', $original))->error);
        $this->assertSame(EInvoiceError::SourceNotFound, $this->refusal(fn () => $this->builder->fromDebitNote(987654321, 'a reason', $original))->error);
    }

    public function testFromSale_RefusesATaxRateThatHasMoreThanTwoDecimals(): void
    {
        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'VAT', '15.1250', 1, '15.13')]);

        $refusal = $this->refusal(fn () => $this->builder->fromSale($saleId));

        $this->assertSame(EInvoiceError::RateNotRepresentable, $refusal->error);
        $this->assertSame('15.1250', $refusal->context['actual']);
    }

    public function testFromSale_RefusesATaxOfACategoryThatIsNotItsTaxableAmountTimesItsRate(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], [$this->tax($cycle['cream'], 0, '40.00'), $this->tax($cycle['perfume'], 1, '66.00')]);

        $refusal = $this->refusal(fn () => $this->builder->fromSale($saleId));

        $this->assertSame(EInvoiceError::RuleBroken, $refusal->error);
        $this->assertSame('BR-CO-17', $refusal->context['rule']);
        $this->assertSame('106.50', $refusal->context['expected']);
        $this->assertSame('106.00', $refusal->context['actual']);
    }

    public function testFromSale_RefusesALineThatIsNotItsQuantityTimesItsPriceLessItsDiscountAfterRounding(): void
    {
        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '10.00')], [$this->tax($item, 0, '1.50')]);

        $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->update(['invoice_discount_share' => '0.0050']);

        $refusal = $this->refusal(fn () => $this->builder->fromSale($saleId));

        $this->assertSame(EInvoiceError::RuleBroken, $refusal->error);
        $this->assertSame('BR-KSA-EN16931-11', $refusal->context['rule']);
        $this->assertSame(1, $refusal->context['line']);
    }

    public function testFromSale_RefusesALineThatComesToLessThanNothing(): void
    {
        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '10.00')], [$this->tax($item, 0, '1.50')]);

        $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->update(['discount' => '150', 'discount_type' => PERCENT]);

        $refusal = $this->refusal(fn () => $this->builder->fromSale($saleId));

        $this->assertSame(EInvoiceError::RuleBroken, $refusal->error);
        $this->assertSame('BR-24', $refusal->context['rule']);
    }

    public function testFromSale_RefusesATaxInvoiceToAnonymousBuyerAndOneToACustomerWithNoName(): void
    {
        $counter = $this->counterSale();

        $this->assertSame(EInvoiceError::StandardNeedsCustomer, $this->refusal(fn () => $this->builder->fromSale($counter, EInvoiceSubtype::Standard))->error);

        $this->makeBusinessCustomer(null, '311111111111113', '');
        $this->accountingDb()->table('people')->where('person_id', $this->customerId)->update(['first_name' => '', 'last_name' => '']);

        $named = $this->referenceSale($this->customerId);

        $this->assertSame(EInvoiceError::BuyerNameMissing, $this->refusal(fn () => $this->builder->fromSale($named, EInvoiceSubtype::Standard))->error);
    }

    public function testFromSale_RefusesASaleWhoseLinesAreAllOfZeroQuantity(): void
    {
        $saleId = $this->referenceSale();

        $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->update(['quantity_purchased' => '0']);

        $this->assertSame(EInvoiceError::NothingToIssue, $this->refusal(fn () => $this->builder->fromSale($saleId))->error);
    }

    public function testFromDebitNote_RefusesANoteWhoseTotalIsNotItsAmountPlusItsTax(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $clerk  = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        $noteId = (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(2), 'amount' => '200', 'tax_percent' => '15', 'note' => 'x'], $clerk);

        $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $noteId)->update(['total' => '230.01']);

        $refusal = $this->refusal(fn () => $this->builder->fromDebitNote($noteId, 'a reason', new OriginalReference(3, 'INV-3', EInvoiceSubtype::Simplified)));

        $this->assertSame(EInvoiceError::TotalsDoNotTie, $refusal->error);
        $this->assertSame('230.01', $refusal->context['expected']);
        $this->assertSame('230.00', $refusal->context['actual']);
    }

    /**
     * @return array<string, mixed>
     */
    private function classifiedTax(int $itemId, int $line, string $percent, string $amount, LineVat $vat): array
    {
        return [...$this->tax($itemId, $line, $amount), 'percent' => $percent, 'vat' => $vat];
    }

    public function testFromSale_WritesTheCategoryAndReasonOfEachLine(): void
    {
        $export   = $this->item('0', false);
        $standard = $this->item('0', false);

        $saleId = $this->save([$this->line($export, 0, '2', '50'), $this->line($standard, 1, '1', '100')], [
            $this->classifiedTax($export, 0, '0.0000', '0.00', new LineVat(null, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT')),
            $this->classifiedTax($standard, 1, '15.0000', '15.00', new LineVat(null, 'S', '15.000', null, null, 'VAT')),
        ]);

        $document = $this->builder->fromSale($saleId);

        $this->assertSame(['Z', 'S'], array_map(static fn ($line): string => $line->vatCategory, $document->lines));
        $this->assertSame('215.00', $document->taxInclusive);
        $this->assertSame('VATEX-SA-32', $document->taxSubtotals[0]->exemptionCode);
        $this->assertSame('Export of goods', $document->taxSubtotals[0]->exemptionText);
    }

    public function testFromSale_SplitsTheSubtotalsByReason(): void
    {
        $this->configureEInvoicing(['einvoice_exempt_category' => 'Z', 'einvoice_exempt_reason_code' => 'VATEX-SA-35', 'einvoice_exempt_reason_text' => 'Medicines']);

        $goods    = $this->item('0', false);
        $services = $this->item('0', false);

        $saleId = $this->save([$this->line($goods, 0, '1', '60'), $this->line($services, 1, '1', '40')], [
            $this->classifiedTax($goods, 0, '0.0000', '0.00', new LineVat(null, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT')),
            $this->classifiedTax($services, 1, '0.0000', '0.00', new LineVat(null, 'Z', '0.000', 'VATEX-SA-33', 'Export of services', 'VAT')),
        ]);

        $document = $this->builder->fromSale($saleId);

        $this->assertCount(2, $document->taxSubtotals);
        $this->assertSame(['VATEX-SA-32', 'VATEX-SA-33'], array_map(static fn ($subtotal): string => $subtotal->exemptionCode, $document->taxSubtotals));
        $this->assertSame(['60.00', '40.00'], array_map(static fn ($subtotal): string => $subtotal->taxable, $document->taxSubtotals));
    }

    public function testFromSale_KeepsTheGlobalExemptionForALineWithoutASnapshot(): void
    {
        $this->configureEInvoicing(['einvoice_exempt_category' => 'E', 'einvoice_exempt_reason_code' => 'VATEX-SA-29', 'einvoice_exempt_reason_text' => 'Financial services']);

        $classified   = $this->item('0', false);
        $unclassified = $this->item('0', false);

        $saleId = $this->save([$this->line($classified, 0, '1', '60'), $this->line($unclassified, 1, '1', '40')], [
            $this->classifiedTax($classified, 0, '0.0000', '0.00', new LineVat(null, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT')),
        ]);

        $document = $this->builder->fromSale($saleId);

        $this->assertSame(['Z', 'E'], array_map(static fn ($subtotal): string => $subtotal->category, $document->taxSubtotals));
        $this->assertSame('VATEX-SA-29', $document->taxSubtotals[1]->exemptionCode);
    }

    public function testFromSale_MergesALineWithoutASnapshotIntoTheSubtotalOfTheSameReason(): void
    {
        $this->configureEInvoicing(['einvoice_exempt_category' => 'Z', 'einvoice_exempt_reason_code' => 'VATEX-SA-32', 'einvoice_exempt_reason_text' => 'Export']);

        $classified   = $this->item('0', false);
        $unclassified = $this->item('0', false);

        $saleId = $this->save([$this->line($classified, 0, '1', '60'), $this->line($unclassified, 1, '1', '40')], [
            $this->classifiedTax($classified, 0, '0.0000', '0.00', new LineVat(null, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT')),
        ]);

        $document = $this->builder->fromSale($saleId);

        $this->assertCount(1, $document->taxSubtotals);
        $this->assertSame('100.00', $document->taxSubtotals[0]->taxable);
        $this->assertSame('Export of goods', $document->taxSubtotals[0]->exemptionText);
    }

    /**
     * Writes what a sale's VAT came to when it was worked out once, as the register keeps it.
     *
     * @param array<string, array{0: string, 1: string, 2: string}> $lines By "item:line": net, VAT, amount with VAT.
     */
    private function keepVat(int $saleId, array $lines, VatCategoryTotal $category): void
    {
        $db = Database::connect();

        foreach ($lines as $key => [$net, $vat, $gross]) {
            [$itemId, $line] = explode(':', $key);
            $db->table('sales_items_vat')->replace(['sale_id' => $saleId, 'item_id' => (int) $itemId, 'line' => (int) $line, 'category' => $category->category, 'percent' => $category->percent, 'exemption_code' => $category->exemptionCode, 'exemption_text' => $category->exemptionText, 'net' => $net, 'vat' => $vat, 'gross' => $gross, 'calc_version' => 1]);
        }

        (new VatSnapshot())->record($saleId, [$category->key() => $category]);
    }

    public function testFromSale_StatesWhatPricesHoldingTheTaxLeaveAsTheRoundingAmount(): void
    {
        $items = [];
        $lines = [];
        $taxes = [];
        $kept  = [];

        for ($line = 0; $line < 10; $line++) {
            $items[]                   = $this->item('0', false);
            $lines[]                   = $this->line($items[$line], $line, '1', '0.99');
            $taxes[]                   = $this->taxRow($items[$line], $line, 'VAT', '15.0000', 0, '0.1290');
            $kept[$items[$line] . ':' . $line] = ['0.86', '0.13', '0.99'];
        }

        $saleId = $this->save($lines, $taxes);
        $this->keepVat($saleId, $kept, new VatCategoryTotal('S', '15.000', null, null, '8.60', '1.29', true));

        $document = $this->builder->fromSale($saleId);

        $this->assertSame(['8.60', '1.29', '9.89', '0.01', '9.90'], [$document->taxExclusive, $document->taxAmount, $document->taxInclusive, $document->rounding, $document->payable()]);
        $this->assertSame(['0.86', '0.13', '0.99'], [$document->lines[0]->netAmount, $document->lines[0]->vatAmount, $document->lines[0]->amountWithVat]);
        $this->assertSame(['8.60', '1.29'], [$document->taxSubtotals[0]->taxable, $document->taxSubtotals[0]->taxAmount]);
    }

    public function testFromSale_StatesTheVatOfACategoryAsTheSaleKeptIt(): void
    {
        $items = [$this->item('0', false), $this->item('0', false), $this->item('0', false)];
        $lines = [];
        $taxes = [];
        $kept  = [];

        foreach ($items as $line => $itemId) {
            $lines[]                  = $this->line($itemId, $line, '1', '0.10');
            $taxes[]                  = $this->tax($itemId, $line, '0.0150');
            $kept[$itemId . ':' . $line] = ['0.10', '0.02', '0.12'];
        }

        $saleId = $this->save($lines, $taxes);
        $this->keepVat($saleId, $kept, new VatCategoryTotal('S', '15.000', null, null, '0.30', '0.05', false));

        $document = $this->builder->fromSale($saleId);

        $this->assertSame(['0.30', '0.05', '0.35', '0.00'], [$document->taxExclusive, $document->taxAmount, $document->taxInclusive, $document->rounding]);
        $this->assertSame('0.02', $document->lines[0]->vatAmount, 'each line states its own VAT (KSA-11)');
    }
}
