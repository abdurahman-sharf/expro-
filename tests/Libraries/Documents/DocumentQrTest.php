<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\DocumentError;
use App\Libraries\Documents\DocumentQr;
use App\Libraries\Documents\QrImage;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DocumentQrTest extends CIUnitTestCase
{
    private const CONFIG = ['company' => 'opes', 'tax_id' => '300000000000003'];

    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Riyadh');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function sale(array $override = []): array
    {
        return $override + [
            'total'            => '1725.0000',
            'taxes'            => [['sale_tax_amount' => '225.0000']],
            'header_charges'   => [],
            'sale_time_raw'    => '2026-10-22 12:47:29',
            'invoice_date_raw' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function fields(?string $payload): array
    {
        $this->assertNotNull($payload);
        $bytes  = base64_decode($payload, true);
        $fields = [];

        for ($offset = 0; $offset < strlen($bytes);) {
            $length                    = ord($bytes[$offset + 1]);
            $fields[ord($bytes[$offset])] = substr($bytes, $offset + 2, $length);
            $offset                   += 2 + $length;
        }

        return $fields;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function typeProvider(): array
    {
        return ['invoice' => ['invoice'], 'tax invoice' => ['tax_invoice'], 'credit note' => ['credit_note']];
    }

    #[DataProvider('typeProvider')]
    public function testForDocument_CarriesTheFiveFieldsOfTheDocumentItself(string $type): void
    {
        $result = (new DocumentQr())->forDocument($type, $this->sale(), self::CONFIG);

        $this->assertNull($result->refusal);
        $this->assertSame([1 => 'opes', 2 => '300000000000003', 3 => '2026-10-22T09:47:29Z', 4 => '1725.00', 5 => '225.00'], $this->fields($result->payload));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function otherTypeProvider(): array
    {
        return [
            'quote'            => ['quote'],
            'work order'       => ['work_order'],
            'purchase invoice' => ['purchase_invoice'],
            'packing slip'     => ['packing_slip'],
            'receipt voucher'  => ['receipt_voucher'],
            'payment voucher'  => ['payment_voucher'],
            'journal'          => ['journal'],
            'nothing'          => [''],
        ];
    }

    #[DataProvider('otherTypeProvider')]
    public function testForDocument_CarriesNoCodeOnAnyOtherDocumentAndSaysNothingAboutIt(string $type): void
    {
        $result = (new DocumentQr())->forDocument($type, $this->sale(), self::CONFIG);

        $this->assertNull($result->payload);
        $this->assertNull($result->refusal);
        $this->assertFalse(DocumentQr::applies($type));
    }

    #[DataProvider('typeProvider')]
    public function testForDocument_RefusesWhileTheTaxNumberOfTheSellerIsNotEntered(string $type): void
    {
        foreach ([[], ['tax_id' => ''], ['tax_id' => "  \t"], ['tax_id' => null]] as $missing) {
            $result = (new DocumentQr())->forDocument($type, $this->sale(), ['company' => 'opes'] + $missing);

            $this->assertNull($result->payload);
            $this->assertSame(DocumentError::QrVatNumberMissing, $result->refusal);
        }
    }

    public function testForDocument_PrintsTheCodeOfTheIssuedDocumentAsItWasIssued(): void
    {
        $issued = base64_encode("\x01\x04opes\x02\x0F300000000000003");
        $result = (new DocumentQr())->forDocument('tax_invoice', $this->sale(['einvoice_qr' => $issued, 'total' => '1.00', 'currency_code' => 'USD']), ['company' => '', 'tax_id' => '']);

        $this->assertSame($issued, $result->payload, 'the code is read from the document, never worked out again');
        $this->assertNull($result->refusal);
        $this->assertNull((new DocumentQr())->forDocument('quote', $this->sale(['einvoice_qr' => $issued]), self::CONFIG)->payload, 'a quote carries no code all the same');
    }

    public function testForDocument_RefusesWhileTheNameOfTheSellerIsNotEntered(): void
    {
        $result = (new DocumentQr())->forDocument('invoice', $this->sale(), ['company' => ' ', 'tax_id' => '300000000000003']);

        $this->assertNull($result->payload);
        $this->assertSame(DocumentError::QrSellerMissing, $result->refusal);
    }

    #[DataProvider('typeProvider')]
    public function testForDocument_CarriesAPayloadTheImageDrawsAtAReadableSizeEvenForTheLongestNameOfASeller(string $type): void
    {
        $longest = ['company' => str_repeat('ش', 127), 'tax_id' => '300000000000003'];
        $sale    = $this->sale(['total' => '123456789012.99', 'taxes' => [['sale_tax_amount' => '16106479436.99']]]);
        $payload = (new DocumentQr())->forDocument($type, $sale, $longest)->payload;
        $image   = new QrImage();

        $this->assertNotNull($payload);
        $this->assertGreaterThan(0, count($image->modules($payload)));
        $this->assertLessThanOrEqual(60.0, $image->sizeMm($payload, QrImage::SIZE_A4_MM));
        $this->assertStringStartsWith("\x89PNG", $image->png($payload));
    }

    public function testForDocument_AsksForTheTaxNumberFirstWhenTheNameIsAlsoMissing(): void
    {
        $result = (new DocumentQr())->forDocument('invoice', $this->sale(), []);

        $this->assertNull($result->payload);
        $this->assertSame(DocumentError::QrVatNumberMissing, $result->refusal);
    }

    public function testForDocument_ShowsACreditNoteAsTheAmountsItTakesBack(): void
    {
        $sale = $this->sale(['total' => '-253.0000', 'taxes' => [['sale_tax_amount' => '-33.0000']]]);

        $fields = $this->fields((new DocumentQr())->forDocument('credit_note', $sale, self::CONFIG)->payload);

        $this->assertSame('253.00', $fields[4]);
        $this->assertSame('33.00', $fields[5]);
    }

    public function testForDocument_AddsEveryTaxRowAndTheTaxOnTheShippingToTheTaxTotal(): void
    {
        $sale = $this->sale([
            'total'          => '1000.0000',
            'taxes'          => [['sale_tax_amount' => '100.0000'], ['sale_tax_amount' => '20.2500']],
            'header_charges' => ['shipping' => '20.0000', 'shipping_tax' => '3.0000', 'shipping_tax_included' => false],
        ]);

        $this->assertSame('123.25', $this->fields((new DocumentQr())->forDocument('invoice', $sale, self::CONFIG)->payload)[5]);
    }

    public function testForDocument_RoundsEachAmountHalfUpToTwoDecimals(): void
    {
        $sale = $this->sale(['total' => '224.2450', 'taxes' => [['sale_tax_amount' => '29.2549']]]);

        $fields = $this->fields((new DocumentQr())->forDocument('invoice', $sale, self::CONFIG)->payload);

        $this->assertSame('224.25', $fields[4]);
        $this->assertSame('29.25', $fields[5]);
    }

    public function testForDocument_CarriesTheTotalWithVatOfASaleWhoseVatWasWorkedOnce(): void
    {
        $sale = $this->sale(['total' => '9.90', 'total_with_vat' => '9.89', 'taxes' => [['sale_tax_amount' => '1.29']]]);

        $fields = $this->fields((new DocumentQr())->forDocument('invoice', $sale, self::CONFIG)->payload);

        $this->assertSame(['9.89', '1.29'], [$fields[4], $fields[5]], 'BT-112, as the signed document carries it, not what the rounding brought the customer to');
    }

    public function testForDocument_WritesZeroTaxForADocumentWithoutTax(): void
    {
        $fields = $this->fields((new DocumentQr())->forDocument('invoice', $this->sale(['taxes' => []]), self::CONFIG)->payload);

        $this->assertSame('0.00', $fields[5]);
    }

    public function testForDocument_AcceptsATotalThatArrivesAsAFloat(): void
    {
        $fields = $this->fields((new DocumentQr())->forDocument('invoice', $this->sale(['total' => 224.25000000000003]), self::CONFIG)->payload);

        $this->assertSame('224.25', $fields[4]);
    }

    public function testForDocument_RefusesAnAmountThatIsNotANumber(): void
    {
        $result = (new DocumentQr())->forDocument('invoice', $this->sale(['total' => 'a lot']), self::CONFIG);

        $this->assertNull($result->payload);
        $this->assertNotNull($result->refusal);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function notIssuedProvider(): array
    {
        return [
            'suspended'  => [['sale_status' => SUSPENDED]],
            'cancelled'  => [['sale_status' => CANCELED]],
            'quote'      => [['sale_type' => SALE_TYPE_QUOTE]],
            'work order' => [['sale_type' => SALE_TYPE_WORK_ORDER]],
        ];
    }

    /**
     * @param array<string, mixed> $facts
     */
    #[DataProvider('notIssuedProvider')]
    public function testForDocument_CarriesNoCodeForASaleThatWasNotIssuedAndSaysNothingAboutIt(array $facts): void
    {
        $result = (new DocumentQr())->forDocument('tax_invoice', $this->sale($facts), self::CONFIG);

        $this->assertNull($result->payload);
        $this->assertNull($result->refusal);
    }

    public function testForDocument_CarriesTheCodeOfACompletedSaleOfEveryIssuedKind(): void
    {
        foreach ([SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN] as $kind) {
            $result = (new DocumentQr())->forDocument('invoice', $this->sale(['sale_status' => COMPLETED, 'sale_type' => $kind]), self::CONFIG);

            $this->assertNotNull($result->payload, 'Sale type ' . $kind);
        }
    }

    public function testForDocument_RefusesADocumentInAForeignCurrencyBecauseItsAmountsAreNotInTheCurrencyOfTheShop(): void
    {
        $result = (new DocumentQr())->forDocument('invoice', $this->sale(['currency_code' => 'USD']), self::CONFIG);

        $this->assertNull($result->payload);
        $this->assertSame(DocumentError::QrForeignCurrency, $result->refusal);
        $this->assertNotNull((new DocumentQr())->forDocument('invoice', $this->sale(['currency_code' => null]), self::CONFIG)->payload);
        $this->assertNotNull((new DocumentQr())->forDocument('invoice', $this->sale(['currency_code' => '']), self::CONFIG)->payload);
    }

    public function testForDocument_AsksForTheTaxNumberBeforeItMentionsTheCurrency(): void
    {
        $result = (new DocumentQr())->forDocument('invoice', $this->sale(['currency_code' => 'USD']), ['company' => 'opes']);

        $this->assertSame(DocumentError::QrVatNumberMissing, $result->refusal);
    }

    public function testForDocument_RefusesWhenTheTimeOfTheSaleIsUnknown(): void
    {
        $result = (new DocumentQr())->forDocument('invoice', $this->sale(['sale_time_raw' => '']), self::CONFIG);

        $this->assertNull($result->payload);
        $this->assertSame(DocumentError::QrTimestampMalformed, $result->refusal);
    }

    public function testForDocument_RefusesASellerNameLongerThanTheFieldHolds(): void
    {
        $result = (new DocumentQr())->forDocument('invoice', $this->sale(), ['company' => str_repeat('ش', 130), 'tax_id' => '300000000000003']);

        $this->assertNull($result->payload);
        $this->assertSame(DocumentError::QrValueTooLong, $result->refusal);
    }

    public function testForDocument_WritesAnArabicSellerNameAsUtf8(): void
    {
        $fields = $this->fields((new DocumentQr())->forDocument('invoice', $this->sale(), ['company' => 'شركة النور', 'tax_id' => '300000000000003'])->payload);

        $this->assertSame('شركة النور', $fields[1]);
    }

    public function testTimestamp_TakesTheTimeOfTheSaleInUniversalTime(): void
    {
        $this->assertSame('2026-10-22T09:47:29Z', DocumentQr::timestamp('2026-10-22 12:47:29', null));
    }

    public function testTimestamp_TakesTheDayOfTheDocumentWhenItIsNotTheDayOfTheSale(): void
    {
        $this->assertSame('2026-10-20T09:47:29Z', DocumentQr::timestamp('2026-10-22 12:47:29', '2026-10-20'));
    }

    public function testTimestamp_IgnoresADayThatIsNotADate(): void
    {
        $this->assertSame('2026-10-22T09:47:29Z', DocumentQr::timestamp('2026-10-22 12:47:29', 'yesterday'));
    }

    public function testTimestamp_CarriesTheNightBeforeIntoTheDayBeforeInUniversalTime(): void
    {
        $this->assertSame('2026-10-21T21:30:00Z', DocumentQr::timestamp('2026-10-22 00:30:00', null));
    }

    public function testTimestamp_IsEmptyForATimeThatCannotBeRead(): void
    {
        $this->assertSame('', DocumentQr::timestamp('not a time', null));
    }
}
