<?php

namespace Tests\Libraries\Accounting\Bank;

use App\Libraries\Accounting\Bank\BankDirectory;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The static directory of the common banks of Saudi Arabia and the Gulf, searched by name in either
 * language, by code and by the names a bank had before.
 */
class BankDirectoryTest extends CIUnitTestCase
{
    /**
     * @param list<array{code: string}> $banks
     *
     * @return list<string>
     */
    private function codes(array $banks): array
    {
        return array_column($banks, 'code');
    }

    public function testSearch_ListsEveryBankWithBothNamesAndTheSaudiBanksFirst(): void
    {
        $all = BankDirectory::search();

        $this->assertGreaterThanOrEqual(50, count($all));
        $this->assertSame('SA', $all[0]['country']);
        $this->assertSame(count($all), count(array_unique($this->codes($all))));

        foreach ($all as $bank) {
            $this->assertNotSame('', $bank['en'], $bank['code']);
            $this->assertNotSame('', $bank['ar'], $bank['code']);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $bank['ar'], $bank['code']);
            $this->assertContains($bank['country'], BankDirectory::COUNTRIES);
            $this->assertMatchesRegularExpression('/^[A-Z]{2}_[A-Z0-9]+$/', $bank['code']);
            $this->assertLessThanOrEqual(24, strlen($bank['code']));
            $this->assertLessThanOrEqual(120, mb_strlen($bank['en']));
        }

        $countries = array_values(array_unique(array_column($all, 'country')));

        $this->assertSame(BankDirectory::COUNTRIES, $countries);

        foreach (BankDirectory::COUNTRIES as $country) {
            $names = array_column(array_filter($all, static fn (array $bank): bool => $bank['country'] === $country), 'en');

            $sorted = $names;

            sort($sorted);

            $this->assertSame($sorted, $names, $country);
        }
    }

    public function testSearch_NamesTheMainSaudiBanksAndTheBankOfTheObservedExample(): void
    {
        foreach (['Al Rajhi Bank', 'Riyad Bank', 'Saudi National Bank', 'Alinma Bank', 'Banque Saudi Fransi', 'Saudi Awwal Bank', 'Arab National Bank', 'Bank Albilad', 'Bank AlJazira', 'Saudi Investment Bank'] as $name) {
            $this->assertNotNull(BankDirectory::codeOfName($name), $name);
        }

        $this->assertSame('SA_RIYAD', BankDirectory::codeOfName('Riyad Bank'));
    }

    public function testSearch_FindsABankByItsEnglishNameItsArabicNameItsCodeOrAFormerName(): void
    {
        $this->assertSame(['SA_RAJHI'], $this->codes(BankDirectory::search('rajhi')));
        $this->assertSame(['SA_RAJHI'], $this->codes(BankDirectory::search('الراجحي')));
        $this->assertSame(['SA_RIYAD'], $this->codes(BankDirectory::search('SA_RIYAD')));
        $this->assertContains('SA_SNB', $this->codes(BankDirectory::search('NCB')));
        $this->assertContains('SA_SNB', $this->codes(BankDirectory::search('سامبا')));
        $this->assertContains('SA_SAB', $this->codes(BankDirectory::search('SABB')));
    }

    public function testSearch_IgnoresCaseSpacesAndTheMarksAndFormsOfArabicLetters(): void
    {
        $this->assertSame(BankDirectory::search('RIYAD  bank'), BankDirectory::search('riyad bank'));
        $this->assertContains('AE_FAB', $this->codes(BankDirectory::search('ابوظبي الاول')));
        $this->assertContains('AE_FAB', $this->codes(BankDirectory::search('أبوظبي الأول')));
        $this->assertContains('SA_ANB', $this->codes(BankDirectory::search('البنك العربى الوطنى')));
        $this->assertContains('AE_RAKBANK', $this->codes(BankDirectory::search('راس الخيمه')));
        $this->assertContains('SA_RIYAD', $this->codes(BankDirectory::search('بَنك الرياض')));
        $this->assertContains('SA_RAJHI', $this->codes(BankDirectory::search('مصرف')));
    }

    public function testSearch_NeedsEveryWordOfTheTermAndFindsNothingForAnUnknownBank(): void
    {
        $this->assertSame(['SA_RIYAD'], $this->codes(BankDirectory::search('riyad bank')));
        $this->assertSame([], BankDirectory::search('riyad rajhi'));
        $this->assertSame([], BankDirectory::search('no such bank at all'));
        $this->assertGreaterThan(3, count(BankDirectory::search('national')));
    }

    public function testNameOf_GivesTheNameInTheLanguageOfTheLocaleAndNullForAnUnknownCode(): void
    {
        $this->assertSame('Riyad Bank', BankDirectory::nameOf('SA_RIYAD', 'en'));
        $this->assertSame('Riyad Bank', BankDirectory::nameOf('SA_RIYAD', 'de-DE'));
        $this->assertSame('بنك الرياض', BankDirectory::nameOf('SA_RIYAD', 'ar-EG'));
        $this->assertSame('بنك الرياض', BankDirectory::nameOf('SA_RIYAD', 'ar-LB'));
        $this->assertNull(BankDirectory::nameOf('XX_NOPE', 'en'));
    }

    public function testStoredNameOf_IsTheEnglishNameAndFindIsNullForAnUnknownCode(): void
    {
        $this->assertSame('Al Rajhi Bank', BankDirectory::storedNameOf('SA_RAJHI'));
        $this->assertNull(BankDirectory::storedNameOf('XX_NOPE'));
        $this->assertNull(BankDirectory::find('XX_NOPE'));
        $this->assertSame(['code' => 'SA_RAJHI', 'country' => 'SA', 'en' => 'Al Rajhi Bank', 'ar' => 'مصرف الراجحي'], BankDirectory::find('SA_RAJHI'));
        $this->assertTrue(BankDirectory::has('SA_RAJHI'));
        $this->assertFalse(BankDirectory::has('sa_rajhi'));
    }

    public function testCodeOfName_FindsABankByTheNameAHandWrittenAccountKeptInEitherLanguage(): void
    {
        $this->assertSame('SA_RIYAD', BankDirectory::codeOfName('  riyad BANK '));
        $this->assertSame('SA_RIYAD', BankDirectory::codeOfName('بنك الرياض'));
        $this->assertNull(BankDirectory::codeOfName('Riyad'));
        $this->assertNull(BankDirectory::codeOfName(''));
        $this->assertNull(BankDirectory::codeOfName('Some local bank'));
    }
}
