<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

class EInvoiceExceptionTest extends CIUnitTestCase
{
    private string $locale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locale = Services::language()->getLocale();
    }

    protected function tearDown(): void
    {
        Services::language()->setLocale($this->locale);

        parent::tearDown();
    }

    private function describedIn(string $locale, EInvoiceException $failure): string
    {
        Services::language()->setLocale($locale);

        return $failure->describe();
    }

    public function testDescribe_SaysThePartOfTheAddressThatIsMissingInTheLanguageOfTheReader(): void
    {
        $seller = new EInvoiceException(EInvoiceError::SellerAddressIncomplete, '', ['field' => 'building number']);
        $buyer  = new EInvoiceException(EInvoiceError::BuyerAddressIncomplete, '', ['name' => 'Sara Co', 'field' => 'postal code']);

        $this->assertSame('The address of the company has no building number.', $this->describedIn('en', $seller));
        $this->assertSame('عنوان الشركة بلا رقم المبنى.', $this->describedIn('ar-EG', $seller));
        $this->assertSame('The address of the customer Sara Co has no postal code.', $this->describedIn('en', $buyer));
        $this->assertStringContainsString('الرمز البريدي', $this->describedIn('ar-LB', $buyer));
        $this->assertStringNotContainsString('postal code', $this->describedIn('ar-LB', $buyer));
    }

    public function testDescribe_SaysTheFigureThatDoesNotTieInTheLanguageOfTheReader(): void
    {
        foreach (['journal total' => 'إجمالي القيد', 'journal tax' => 'ضريبة القيد', 'journal revenue' => 'إيراد القيد', 'total' => 'الإجمالي', 'tax' => 'الضريبة'] as $rule => $arabic) {
            $failure = new EInvoiceException(EInvoiceError::TotalsDoNotTie, '', ['rule' => $rule, 'expected' => '30.00', 'actual' => '29.00']);

            $this->assertSame('The document does not tie to the books (' . $rule . '): the books say 30.00 and the document 29.00.', $this->describedIn('en', $failure));
            $this->assertStringContainsString('(' . $arabic . ')', $this->describedIn('ar-EG', $failure), $rule);
        }
    }

    public function testDescribe_SaysTheFieldThatIsTooLongAndReadsTheSellerPrefixOfAFieldOfTheSettings(): void
    {
        $street = new EInvoiceException(EInvoiceError::AddressInvalid, '', ['field' => 'seller_street', 'expected' => 1000]);
        $number = new EInvoiceException(EInvoiceError::IdentificationInvalid, '', ['field' => 'identification_number', 'expected' => 60]);

        $this->assertSame('The address is not valid: street', $this->describedIn('en', $street));
        $this->assertSame('العنوان غير صالح: الشارع', $this->describedIn('ar-EG', $street));
        $this->assertSame('The identification is not valid: identification number', $this->describedIn('en', $number));
        $this->assertStringContainsString('رقم المعرِّف', $this->describedIn('ar-EG', $number));
    }

    public function testDescribe_LeavesAValueThatHappensToBeAWordAsItIs(): void
    {
        $identification = new EInvoiceException(EInvoiceError::IdentificationInvalid, '', ['field' => 'street']);
        $postal         = new EInvoiceException(EInvoiceError::AddressInvalid, '', ['field' => 'city']);
        $rule           = new EInvoiceException(EInvoiceError::RuleBroken, '', ['rule' => 'tax']);

        $this->assertSame('The identification is not valid: street', $this->describedIn('en', $identification));
        $this->assertSame('المعرِّف غير صالح: street', $this->describedIn('ar-EG', $identification));
        $this->assertSame('The address is not valid: city', $this->describedIn('en', $postal));
        $this->assertSame('The document breaks the rule tax of the standard.', $this->describedIn('en', $rule));
    }

    public function testDescribe_LeavesTheCodeOfARuleOfTheStandardAsItIs(): void
    {
        $failure = new EInvoiceException(EInvoiceError::RuleBroken, '', ['rule' => 'BR-KSA-EN16931-11']);

        $this->assertSame('The document breaks the rule BR-KSA-EN16931-11 of the standard.', $this->describedIn('en', $failure));
        $this->assertStringContainsString('BR-KSA-EN16931-11', $this->describedIn('ar-EG', $failure));
    }

    public function testDescribe_SaysTheDocumentOfAnotherCustomerInBothLanguages(): void
    {
        $failure = new EInvoiceException(EInvoiceError::OriginalOfAnotherCustomer, '', ['number' => 'INV-0007']);

        $this->assertSame('The debit note is not of the customer of the document INV-0007: a debit note refers to a document of its own customer.', $this->describedIn('en', $failure));
        $this->assertStringContainsString('INV-0007', $this->describedIn('ar-EG', $failure));
        $this->assertStringNotContainsString('error_original_of_another_customer', $this->describedIn('ar-EG', $failure));
    }
}
