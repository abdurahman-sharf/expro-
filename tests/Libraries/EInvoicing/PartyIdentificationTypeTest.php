<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\PartyIdentificationType;
use CodeIgniter\Test\CIUnitTestCase;

class PartyIdentificationTypeTest extends CIUnitTestCase
{
    public function testCodes_ListTheElevenObservedCodes(): void
    {
        $this->assertSame(['CRN', 'MOM', 'MLS', 'SAG', '700', 'TIN', 'NAT', 'IQA', 'GCC', 'PAS', 'OTH'], PartyIdentificationType::codes());
    }

    public function testForSeller_ListsTheSixSchemesTheSpecificationAllowsTheSeller(): void
    {
        $this->assertSame(['CRN', 'MOM', 'MLS', 'SAG', '700', 'OTH'], array_map(static fn (PartyIdentificationType $type): string => $type->value, PartyIdentificationType::forSeller()));
    }

    public function testForSellerOrNull_RejectsASchemeOfAPerson(): void
    {
        $this->assertNull(PartyIdentificationType::forSellerOrNull('NAT'));
        $this->assertNull(PartyIdentificationType::forSellerOrNull('TIN'));
        $this->assertNull(PartyIdentificationType::forSellerOrNull('XXX'));
        $this->assertSame(PartyIdentificationType::CommercialRegistration, PartyIdentificationType::forSellerOrNull('CRN'));
    }

    public function testLanguageKey_NamesAnEntryOfTheLanguageFile(): void
    {
        $this->assertSame('Accounting_einvoicing.identification_700', PartyIdentificationType::SevenHundredNumber->languageKey());
    }
}
