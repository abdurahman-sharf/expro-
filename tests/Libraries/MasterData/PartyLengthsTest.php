<?php

namespace Tests\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\MasterData\PartyLengths;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class PartyLengthsTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function fieldProvider(): array
    {
        return ['business name' => ['company_name', 100], 'city' => ['city', 100], 'state' => ['state', 100], 'postal code' => ['zip', 20]];
    }

    #[DataProvider('fieldProvider')]
    public function testCheck_AcceptsTheLongestValueAndRejectsOneCharacterMoreNamingTheFieldAndTheLimit(string $field, int $max): void
    {
        PartyLengths::check([$field => str_repeat('م', $max)], null, []);

        try {
            PartyLengths::check([$field => str_repeat('م', $max + 1)], null, [$field => 'The label']);
            $this->fail('A value over the limit was accepted.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::PartyFieldTooLong, $failure->error);
            $this->assertSame(['max' => $max, 'name' => 'The label'], $failure->context);
        }
    }

    public function testCheck_AcceptsALongValueThatIsTheOneAlreadyStoredAndRejectsItOnceChanged(): void
    {
        $long = str_repeat('x', 130);

        PartyLengths::check(['city' => $long], (object) ['city' => $long], []);

        $this->expectException(AccountingException::class);

        PartyLengths::check(['city' => $long . 'y'], (object) ['city' => $long], []);
    }

    public function testCheck_LeavesAFieldThatWasNotGivenAlone(): void
    {
        PartyLengths::check([], (object) ['city' => str_repeat('x', 300)], []);

        $this->addToAssertionCount(1);
    }

    public function testCheck_FallsBackToTheNameOfTheFieldWhenNoLabelIsGiven(): void
    {
        try {
            PartyLengths::check(['zip' => str_repeat('1', 21)], null, []);
            $this->fail('A value over the limit was accepted.');
        } catch (AccountingException $failure) {
            $this->assertSame('zip', $failure->context['name']);
        }
    }
}
