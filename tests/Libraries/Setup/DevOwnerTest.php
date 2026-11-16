<?php

namespace Tests\Libraries\Setup;

use App\Libraries\Setup\DevOwner;
use App\Libraries\Setup\InstallState;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\InstallStateFixtureTrait;

class DevOwnerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
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
        $this->makeInstallFresh();
    }

    protected function tearDown(): void
    {
        $this->restoreInstall();

        parent::tearDown();
    }

    public function testCreate_MakesTheOwnerAndFinishesTheSetup(): void
    {
        $personId = (new DevOwner())->create('dev.owner', 'a-throwaway-1');

        $this->assertSame(InstallState::OWNER_ID, $personId);
        $this->assertSame(InstallState::COMPLETE, (new InstallState())->state());

        $row = $this->installDb()->table('employees')->where('person_id', $personId)->get()->getRow();

        $this->assertSame('dev.owner', $row->username);
        $this->assertTrue(password_verify('a-throwaway-1', $row->password));
        $this->assertSame(0, $this->installDb()->table('app_config')->where('key', InstallState::CODE_KEY)->countAllResults());
        $this->assertGreaterThan(0, $this->installDb()->table('grants')->where('person_id', $personId)->where('permission_id', 'accounting_settings')->countAllResults());
    }

    public function testCreate_RefusesOutsideDevelopmentAndTesting(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            (new DevOwner(null, null, 'production'))->create('dev.owner', 'a-throwaway-1');
        } finally {
            $this->assertSame(InstallState::PENDING_OWNER, (new InstallState())->state());
        }
    }

    public function testCreate_RefusesAnInstallationThatIsNotWaitingForItsOwner(): void
    {
        $this->setInstallState(InstallState::COMPLETE);

        $this->expectException(RuntimeException::class);

        (new DevOwner())->create('dev.owner', 'a-throwaway-1');
    }

    public function testCreate_RefusesAnEmptyPasswordAndAnInvalidUsername(): void
    {
        foreach ([['dev.owner', ''], ['no', 'a-throwaway-1'], ['has space', 'a-throwaway-1']] as [$username, $password]) {
            try {
                (new DevOwner())->create($username, $password);
                $this->fail("{$username} was accepted.");
            } catch (RuntimeException) {
                $this->assertSame(InstallState::PENDING_OWNER, (new InstallState())->state());
            }
        }
    }
}
