<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;

/**
 * The command that runs the scan from cron: what it says, that a second run keeps nothing new, that it writes only the
 * findings and its line in the audit log, and that it is refused, with its reason, while the agent is off.
 *
 * @internal
 */
final class AccountingAiScanTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AiFixtureTrait;
    use DatabaseFingerprintTrait;

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
        $journal = $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), '25000.00', 'Round one');
        $this->postedAt($journal, date('Y-m-d H:i:s'));
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $params
     *
     * @return array{int, string}
     */
    private function scan(array $params = []): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('accounting:ai_scan', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    public function testRun_KeepsTheFindingsAndSaysSoWithExitZero(): void
    {
        [$exit, $output] = $this->scan();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('AI-ROUND: 1 new, 0 already known', $output);
        $this->assertStringContainsString('RESULT: 1 new, 0 already known.', $output);
        $this->assertSame(1, $this->countRows('accounting_ai_findings'));
    }

    public function testRun_KeepsNothingNewWhenItIsRunAgain(): void
    {
        $this->scan();
        [$exit, $output] = $this->scan();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('RESULT: 0 new, 1 already known.', $output);
        $this->assertSame(1, $this->countRows('accounting_ai_findings'));
    }

    public function testRun_WritesOnlyTheFindingsAndItsLineInTheAuditLog(): void
    {
        $before = $this->databaseFingerprint();
        $this->scan();
        $after = $this->databaseFingerprint();

        $changed = array_keys(array_filter($after, static fn (array $print, string $table): bool => $print !== ($before[$table] ?? null), ARRAY_FILTER_USE_BOTH));

        $this->assertEqualsCanonicalizing(['ospos_accounting_ai_findings', 'ospos_accounting_audit_log'], $changed);
        $this->assertNull($this->accountingDb()->table('accounting_audit_log')->where('action', 'scan_run')->get()->getRow()->employee_id);
    }

    public function testRun_IsRefusedWithExitOneAndTheReasonWhileTheAgentIsOff(): void
    {
        $this->setConfigValue('accounting_ai_enabled', '0');

        $before = $this->databaseFingerprint();

        [$exit, $output] = $this->scan();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(lang('Accounting.error_ai_disabled'), $output);
        $this->assertSame($before, $this->databaseFingerprint());
    }

    public function testRun_IsRefusedWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        [$exit, $output] = $this->scan();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(lang('Accounting.error_ai_disabled'), $output);
        $this->assertSame(0, $this->countRows('accounting_ai_findings'));
    }

    public function testRun_LooksFromTheDayGivenAndTakesTheDayGivenForToday(): void
    {
        [$exit, $output] = $this->scan(['since' => '2030-01-01']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('AI SCAN since 2030-01-01', $output);
        $this->assertSame(0, $this->countRows('accounting_ai_findings'));

        [, $output] = $this->scan(['date' => '2026-12-31']);

        $this->assertStringContainsString('AI SCAN since 2026-10-02', $output);
    }

    public function testRun_RefusesADateThatIsNotADayWithExitSeven(): void
    {
        foreach ([['since' => 'yesterday'], ['date' => '2026-13-01'], ['since' => '2026-02-30']] as $params) {
            [$exit, $output] = $this->scan($params);

            $this->assertSame(7, $exit);
            $this->assertStringContainsString('days, YYYY-MM-DD', $output);
        }

        $this->assertSame(0, $this->countRows('accounting_ai_findings'));
    }
}
