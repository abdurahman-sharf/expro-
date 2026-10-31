<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckGroup;
use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class CheckGroupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CheckGroup $group;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->group = new class (Database::connect()) extends CheckGroup {
            public function run(): array
            {
                return [];
            }

            public function try(array $requires, callable $work): CheckResult
            {
                return $this->check('the_id', 'THE-REF', 'The title', $requires, $work);
            }
        };
    }

    public function testCheck_GivesWhatTheCheckFoundTheNameOfTheCheck(): void
    {
        $result = $this->group->try([], static fn (): CheckResult => CheckResult::fail('Broken.', ['one']));

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['one'], $result->findings);
        $this->assertSame('the_id', $result->id);
        $this->assertSame('THE-REF', $result->ref);
        $this->assertSame('The title', $result->title);
    }

    public function testCheck_SkipsACheckWhoseTableDoesNotExistYetAndDoesNotRunIt(): void
    {
        $ran    = false;
        $result = $this->group->try(['accounting_journals', 'accounting_no_such_table'], static function () use (&$ran): CheckResult {
            $ran = true;

            return CheckResult::pass('ran');
        });

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertFalse($ran);
        $this->assertSame(['accounting_no_such_table'], $result->data['missing']);
        $this->assertStringContainsString('accounting_no_such_table', $result->summary);
        $this->assertSame('the_id', $result->id);
    }

    public function testCheck_SkipsACheckWhoseColumnDoesNotExistYet(): void
    {
        $result = $this->group->try(['treasuries.no_such_column'], static fn (): CheckResult => CheckResult::pass('ran'));

        $this->assertSame(CheckStatus::Skipped, $result->status);
    }

    public function testCheck_RunsACheckWhoseSchemaIsThere(): void
    {
        $result = $this->group->try(['accounting_journals', 'treasuries.bank_name'], static fn (): CheckResult => CheckResult::pass('ran'));

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    public function testCheck_ReportsACheckThatThrowsAsBrokenInsteadOfStoppingTheReport(): void
    {
        $result = $this->group->try([], static function (): CheckResult {
            throw new RuntimeException('the table is corrupt');
        });

        $this->assertSame(CheckStatus::Error, $result->status);
        $this->assertStringContainsString('the table is corrupt', $result->summary);
        $this->assertSame('the_id', $result->id);
        $this->assertTrue($result->status->blocks());
    }

    public function testCheck_ReportsAnErrorOfThePhpEngineAsBrokenToo(): void
    {
        $result = $this->group->try([], static function (): CheckResult {
            return CheckResult::pass((string) intdiv(1, 0));
        });

        $this->assertSame(CheckStatus::Error, $result->status);
    }

    public function testCheck_ReportsADatabaseErrorAsBroken(): void
    {
        $result = $this->group->try([], static function (): CheckResult {
            Database::connect()->query('SELECT * FROM ospos_accounting_no_such_table');

            return CheckResult::pass('ran');
        });

        $this->assertSame(CheckStatus::Error, $result->status);
    }
}
