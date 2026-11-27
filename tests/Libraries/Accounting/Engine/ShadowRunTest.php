<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\Engine\PostingFacts;
use App\Libraries\Accounting\Engine\ShadowRun;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use TypeError;

class ShadowRunTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

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

        $this->reinstallChart();
        $this->accountingDb()->table('accounting_posting_shadow_diffs')->truncate();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('accounting_posting_shadow_diffs')->truncate();

        parent::tearDown();
    }

    private function legacy(): JournalDraft
    {
        return new JournalDraft(date: '2026-10-21', lines: [new JournalLineDraft(5, debit: '100'), new JournalLineDraft(9, credit: '100')], sourceType: 'expense', sourceId: 3, branchId: 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failures(): array
    {
        return array_map(static fn (object $row): array => json_decode((string) $row->differences, true), $this->accountingDb()->table('accounting_posting_shadow_diffs')->get()->getResult());
    }

    public function testCheck_WritesDownAnyThrowableOfTheEngineByItsClassAndLeavesTheTransactionClean(): void
    {
        $db = $this->accountingDb();

        foreach ([new TypeError('bad argument'), new RuntimeException('broken')] as $thrown) {
            $db->transStart();
            (new ShadowRun())->check('expense', $this->legacy(), static fn (): PostingFacts => throw $thrown, 'expense', 3, 1);
            $clean = $db->transStatus();
            $db->transComplete();

            $this->assertTrue($clean, $thrown::class);
        }

        $this->assertSame(['TypeError: bad argument', 'RuntimeException: broken'], array_column($this->failures(), 'engine_failed'));
    }

    public function testCheck_LiftsTheMarkAFailedQueryOfTheEngineLeavesOnTheTransactionOfTheSave(): void
    {
        $db = $this->accountingDb();
        $db->transStart();

        (new ShadowRun())->check('expense', $this->legacy(), static function () use ($db): PostingFacts {
            $db->query('SELECT * FROM a_table_that_does_not_exist');

            throw new RuntimeException('after the failed query');
        }, 'expense', 3, 1);

        $clean = $db->transStatus();
        $db->transComplete();

        $this->assertTrue($clean);
        $this->assertCount(1, $this->failures());
    }

    public function testCheck_KeepsATransactionThatWasAlreadyMarkedToRollBackMarked(): void
    {
        $db = $this->accountingDb();
        $db->transStart();
        $db->query('SELECT * FROM a_table_that_does_not_exist');

        (new ShadowRun())->check('expense', $this->legacy(), static fn (): PostingFacts => throw new RuntimeException('x'), 'expense', 3, 1);

        $clean = $db->transStatus();
        $db->transComplete();
        $db->resetTransStatus();

        $this->assertFalse($clean);
    }

    public function testCheck_LogsADifferenceItCannotWriteAndThrowsNothing(): void
    {
        $db    = $this->accountingDb();
        $table = $db->prefixTable('accounting_posting_shadow_diffs');
        $db->query("RENAME TABLE `{$table}` TO `{$table}_away`");

        try {
            $db->transStart();
            (new ShadowRun())->check('expense', $this->legacy(), static fn (): PostingFacts => throw new RuntimeException('x'), 'expense', 3, 1);
            $clean = $db->transStatus();
            $db->transComplete();
        } finally {
            $db->query("RENAME TABLE `{$table}_away` TO `{$table}`");
        }

        $this->assertTrue($clean);
        $this->assertLogContains('error', 'The shadow check of expense expense 3 could not write its difference');
    }
}
