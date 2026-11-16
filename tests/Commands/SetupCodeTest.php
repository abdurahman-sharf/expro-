<?php

namespace Tests\Commands;

use App\Libraries\Setup\InstallState;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\InstallStateFixtureTrait;

class SetupCodeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use InstallStateFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotInstall();
    }

    protected function tearDown(): void
    {
        $this->restoreInstall();

        parent::tearDown();
    }

    public function testRun_MakesANewCodeAndPrintsItWhileTheInstallationWaitsForItsOwner(): void
    {
        $old = $this->makeInstallFresh();

        $this->resetStreamFilterBuffer();
        command('setup:code');

        preg_match('/Owner setup code: ([A-Z2-9]{4}(?:-[A-Z2-9]{4}){3})/', $this->getStreamFilterBuffer(), $found);

        $this->assertNotEmpty($found);
        $this->assertNotSame($old, $found[1]);

        $state = new InstallState();

        $this->assertTrue($state->codeMatches($found[1]));
        $this->assertFalse($state->codeMatches($old));
    }

    public function testRun_RefusesAnInstallationThatIsNotWaitingForItsOwner(): void
    {
        $this->setInstallState(InstallState::COMPLETE);

        $this->resetStreamFilterBuffer();
        command('setup:code');

        $this->assertStringContainsString('not waiting for its owner', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('Owner setup code:', $this->getStreamFilterBuffer());
    }
}
