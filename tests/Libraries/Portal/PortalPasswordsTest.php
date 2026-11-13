<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Portal\PortalPasswords;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PortalPasswordsTest extends CIUnitTestCase
{
    private function refusalOf(string $password, string $username = 'ahmed', ?string $current = null): ?AccountingError
    {
        try {
            PortalPasswords::check($password, $username, $current);
        } catch (AccountingException $failure) {
            return $failure->error;
        }

        return null;
    }

    public function testHash_KeepsOnlyAHashThatTheAlgorithmOfTheEmployeeLoginMakes(): void
    {
        $hash = PortalPasswords::hash('Correct-Horse-9');

        $this->assertNotSame('Correct-Horse-9', $hash);
        $this->assertStringNotContainsString('Correct-Horse-9', $hash);
        $this->assertSame(password_get_info(password_hash('x', PASSWORD_DEFAULT))['algoName'], password_get_info($hash)['algoName']);
        $this->assertTrue(PortalPasswords::verify('Correct-Horse-9', $hash));
        $this->assertFalse(PortalPasswords::verify('Correct-Horse-8', $hash));
    }

    public function testHash_MakesAnotherHashEveryTimeForTheSamePassword(): void
    {
        $this->assertNotSame(PortalPasswords::hash('Correct-Horse-9'), PortalPasswords::hash('Correct-Horse-9'));
    }

    public function testNeedsRehash_IsFalseForAFreshHashAndTrueForAHashOfALowerCost(): void
    {
        $this->assertFalse(PortalPasswords::needsRehash(PortalPasswords::hash('Correct-Horse-9')));
        $this->assertTrue(PortalPasswords::needsRehash(password_hash('Correct-Horse-9', PASSWORD_BCRYPT, ['cost' => 4])));
    }

    public function testAbsent_IsAHashThatNoPasswordMatches(): void
    {
        $this->assertSame(PortalPasswords::absent(), PortalPasswords::absent());
        $this->assertFalse(PortalPasswords::verify('', PortalPasswords::absent()));
        $this->assertFalse(PortalPasswords::verify('password', PortalPasswords::absent()));
    }

    public function testTemporary_MakesALongPasswordOfUnambiguousCharactersThatIsNotRepeated(): void
    {
        $seen = [];

        for ($index = 0; $index < 30; $index++) {
            $password = PortalPasswords::temporary();

            $this->assertSame(PortalPasswords::TEMPORARY_LENGTH, strlen($password));
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]+$/', $password);
            $seen[$password] = true;
        }

        $this->assertCount(30, $seen);
    }

    public function testTemporary_MeetsTheRulesOfAPasswordTheCustomerChooses(): void
    {
        $this->assertNull($this->refusalOf(PortalPasswords::temporary()));
    }

    public function testCheck_AcceptsTenCharactersAndRejectsNine(): void
    {
        $this->assertNull($this->refusalOf(str_repeat('a', PortalPasswords::MIN_LENGTH)));
        $this->assertSame(AccountingError::PortalPasswordInvalid, $this->refusalOf(str_repeat('a', PortalPasswords::MIN_LENGTH - 1)));
    }

    public function testCheck_AcceptsSeventyTwoBytesAndRejectsSeventyThree(): void
    {
        $this->assertNull($this->refusalOf(str_repeat('a', PortalPasswords::MAX_BYTES)));
        $this->assertSame(AccountingError::PortalPasswordInvalid, $this->refusalOf(str_repeat('a', PortalPasswords::MAX_BYTES + 1)));
    }

    public function testCheck_CountsTheBytesOfAnArabicPasswordForTheLimitOfTheHash(): void
    {
        $this->assertNull($this->refusalOf(str_repeat('م', 36)));
        $this->assertSame(AccountingError::PortalPasswordInvalid, $this->refusalOf(str_repeat('م', 37)));
    }

    public function testCheck_RejectsAPasswordThatIsTheUserNameInAnyCase(): void
    {
        $this->assertSame(AccountingError::PortalPasswordInvalid, $this->refusalOf('Ahmed-Customer', 'ahmed-customer'));
    }

    public function testCheck_RejectsAPasswordThatIsTheCurrentOne(): void
    {
        $this->assertSame(AccountingError::PortalPasswordInvalid, $this->refusalOf('Correct-Horse-9', 'ahmed', 'Correct-Horse-9'));
        $this->assertNull($this->refusalOf('Correct-Horse-8', 'ahmed', 'Correct-Horse-9'));
    }

    public function testCheck_SaysInItsMessageThatTheUpperLimitIsInBytesAndGivesBothLimits(): void
    {
        try {
            PortalPasswords::check('short', 'ahmed', null);
            $this->fail('No refusal');
        } catch (AccountingException $failure) {
            $message = AccountingMessage::of($failure);
        }

        $this->assertSame(lang('Accounting.error_portal_password_invalid', [(string) PortalPasswords::MAX_BYTES, (string) PortalPasswords::MIN_LENGTH]), $message);
        $this->assertStringContainsString(PortalPasswords::MIN_LENGTH . ' characters', $message);
        $this->assertStringContainsString(PortalPasswords::MAX_BYTES . ' bytes', $message);
    }

    public function testCheck_SaysInTheHintOfThePasswordFormThatTheUpperLimitIsInBytes(): void
    {
        $hint = lang('Portal.password_hint', [PortalPasswords::MIN_LENGTH, PortalPasswords::MAX_BYTES]);

        $this->assertStringContainsString(PortalPasswords::MIN_LENGTH . ' characters', $hint);
        $this->assertStringContainsString(PortalPasswords::MAX_BYTES . ' bytes', $hint);
    }
}
