<?php

namespace Tests\Libraries\Setup;

use App\Libraries\Setup\InstallState;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\InstallStateFixtureTrait;

class InstallStateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use InstallStateFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private InstallState $state;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotInstall();
        $this->state = new InstallState();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['setup.code']);
        $this->restoreInstall();

        parent::tearDown();
    }

    private function storedCodeHash(): string
    {
        return (string) ($this->installDb()->table('app_config')->where('key', InstallState::CODE_KEY)->get()->getRow()->value ?? '');
    }

    public function testState_IsCompleteWhenNothingWasWrittenSoADatabaseMadeBeforeThisKeepsItsState(): void
    {
        $this->installDb()->table('app_config')->where('key', InstallState::KEY)->delete();

        $this->assertSame(InstallState::COMPLETE, $this->state->state());
        $this->assertTrue($this->state->isComplete());
        $this->assertFalse($this->state->needsOwner());
        $this->assertFalse($this->state->needsSetup());
    }

    public function testInitializeFreshInstall_MarksTheDatabaseAsWaitingForItsOwner(): void
    {
        $code = $this->makeInstallFresh();

        $this->assertSame(InstallState::PENDING_OWNER, $this->state->state());
        $this->assertTrue($this->state->needsOwner());
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $code);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->state->installId());
    }

    public function testInitializeFreshInstall_KeepsTheCodeOnlyAsAHashInTheDatabase(): void
    {
        $code = $this->makeInstallFresh();
        $hash = $this->storedCodeHash();

        $this->assertNotSame('', $hash);
        $this->assertStringNotContainsString(str_replace('-', '', $code), $hash);
        $this->assertTrue(password_verify(str_replace('-', '', $code), $hash));
    }

    public function testInitializeFreshInstall_LeavesTheCodeInAFileOnlyTheServerCanRead(): void
    {
        $code = $this->makeInstallFresh();
        $file = WRITEPATH . InstallState::CODE_FILE;

        $this->assertFileExists($file);
        $this->assertStringContainsString($code, (string) file_get_contents($file));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
    }

    public function testInitializeFreshInstall_TakesAwayThePasswordThePlantedAccountHad(): void
    {
        $this->makeInstallFresh();

        $row = $this->installDb()->table('employees')->where('person_id', InstallState::OWNER_ID)->get()->getRow();

        $this->assertFalse(password_verify('pointofsale', $row->password));
        $this->assertSame('2', (string) $row->hash_version);
    }

    public function testInitializeFreshInstall_MakesADifferentCodeAndAccountPasswordEachTime(): void
    {
        $first      = $this->makeInstallFresh();
        $firstHash  = $this->installDb()->table('employees')->where('person_id', InstallState::OWNER_ID)->get()->getRow()->password;
        $second     = $this->makeInstallFresh();
        $secondHash = $this->installDb()->table('employees')->where('person_id', InstallState::OWNER_ID)->get()->getRow()->password;

        $this->assertNotSame($first, $second);
        $this->assertNotSame($firstHash, $secondHash);
    }

    public function testInitializeFreshInstall_UsesTheCodeOfTheEnvironmentAndWritesNoFileWhenOneIsGiven(): void
    {
        $_SERVER['setup.code'] = 'abcd efgh jkmn pqrs';

        $code = $this->makeInstallFresh();

        $this->assertSame('ABCD-EFGH-JKMN-PQRS', $code);
        $this->assertFileDoesNotExist(WRITEPATH . InstallState::CODE_FILE);
        $this->assertTrue($this->state->codeMatches('ABCD-EFGH-JKMN-PQRS'));
        $this->assertFalse($this->state->codeMatches('ABCD-EFGH-JKMN-PQRT'));
    }

    public function testCodeMatches_AcceptsTheCodeHoweverItIsTypedAndRefusesAnyOtherOrNone(): void
    {
        $code = $this->makeInstallFresh();

        $this->assertTrue($this->state->codeMatches($code));
        $this->assertTrue($this->state->codeMatches(strtolower($code)));
        $this->assertTrue($this->state->codeMatches(str_replace('-', ' ', $code)));
        $this->assertFalse($this->state->codeMatches('AAAA-AAAA-AAAA-AAAA'));
        $this->assertFalse($this->state->codeMatches(''));
        $this->assertFalse($this->state->codeMatches('---'));
    }

    public function testCodeMatches_IsFalseWhenThereIsNoCodeToMatch(): void
    {
        $this->installDb()->table('app_config')->where('key', InstallState::CODE_KEY)->delete();

        $this->assertFalse($this->state->codeMatches('ABCD-EFGH-JKMN-PQRS'));
    }

    public function testRenewCode_MakesEveryOlderCodeUseless(): void
    {
        $old = $this->makeInstallFresh();
        $new = $this->state->renewCode();

        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        $this->assertFalse($this->state->codeMatches($old));
        $this->assertTrue($this->state->codeMatches($new));
        $this->assertStringContainsString($new, (string) file_get_contents(WRITEPATH . InstallState::CODE_FILE));
    }

    public function testRenewCode_GivesNothingOnceTheInstallationHasItsOwner(): void
    {
        $this->setInstallState(InstallState::PENDING_SETUP);

        $this->assertNull($this->state->renewCode());

        $this->setInstallState(InstallState::COMPLETE);

        $this->assertNull($this->state->renewCode());
    }

    public function testTakeOwnerPlace_SucceedsOnceAndFailsForEveryoneAfter(): void
    {
        $this->makeInstallFresh();

        $this->assertTrue($this->state->takeOwnerPlace());
        $this->assertFalse($this->state->takeOwnerPlace());
        $this->assertSame(InstallState::PENDING_SETUP, $this->state->state());
    }

    public function testMarkSetUp_ClosesTheSetupOnlyWhenItWasOpen(): void
    {
        $this->makeInstallFresh();

        $this->assertFalse($this->state->markSetUp());

        $this->state->takeOwnerPlace();

        $this->assertTrue($this->state->markSetUp());
        $this->assertTrue($this->state->isComplete());
        $this->assertFalse($this->state->markSetUp());
    }

    public function testForgetCode_RemovesTheHashAndTheFile(): void
    {
        $code = $this->makeInstallFresh();

        $this->state->forgetCode();

        $this->assertSame('', $this->storedCodeHash());
        $this->assertFileDoesNotExist(WRITEPATH . InstallState::CODE_FILE);
        $this->assertFalse($this->state->codeMatches($code));
    }

    public function testState_KeepsWhatARestoredBackupSaidAboutItself(): void
    {
        foreach ([InstallState::PENDING_OWNER, InstallState::PENDING_SETUP, InstallState::COMPLETE] as $stored) {
            $this->setInstallState($stored);

            $this->assertSame($stored, (new InstallState())->state());
        }
    }
}
