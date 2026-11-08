<?php

namespace Tests\Commands;

use App\Libraries\Accounting\Ai\AiSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * The kill switch from the console: it shows the state, turns the agent off at once and on again, and writes each
 * change as the system's.
 *
 * @internal
 */
final class AccountingAiSwitchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AiFixtureTrait;

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

        $this->startAgent();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    /**
     * @return array{int, string}
     */
    private function switch(string ...$params): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('accounting:ai_switch', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    public function testRun_ShowsTheStateByDefault(): void
    {
        [$exit, $output] = $this->switch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('The AI agent is on.', $output);

        $this->setConfigValue('accounting_ai_enabled', '0');

        $this->assertStringContainsString('The AI agent is off.', $this->switch('status')[1]);
    }

    public function testRun_TurnsTheAgentOffAtOnceAndWritesItAsTheSystems(): void
    {
        [$exit, $output] = $this->switch('off');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('The AI agent is off.', $output);
        $this->assertFalse((new AiSettings())->isEnabled());

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('action', 'ai_disabled')->get()->getRow();

        $this->assertNull($entry->employee_id);
        $this->assertSame('system', $entry->origin);
    }

    public function testRun_TurnsTheAgentOnAgain(): void
    {
        $this->setConfigValue('accounting_ai_enabled', '0');

        [$exit, $output] = $this->switch('on');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('The AI agent is on.', $output);
        $this->assertTrue((new AiSettings())->isEnabled());
        $this->assertSame(1, (int) $this->accountingDb()->table('accounting_audit_log')->where('action', 'ai_enabled')->countAllResults());
    }

    public function testRun_RefusesAWordItDoesNotKnowWithExitSevenAndChangesNothing(): void
    {
        [$exit, $output] = $this->switch('maybe');

        $this->assertSame(7, $exit);
        $this->assertStringContainsString('status, on or off', $output);
        $this->assertTrue((new AiSettings())->isEnabled());
    }

    public function testRun_SwitchesOffTheAgentThatIsOnWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $this->switch('off');

        $this->assertFalse((new AiSettings())->isSwitchedOn());
    }
}
