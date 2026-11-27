<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingPostingRulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
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

    private function runCommand(string $arguments = ''): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:posting_rules ' . $arguments);

        return $this->getStreamFilterBuffer();
    }

    public function testRun_ListsTheRulesOfTheExpenseWithTheirModeAndEveryLine(): void
    {
        $output = $this->runCommand('expense');

        $this->assertStringContainsString('expense version 1, from 1900-01-01, mode shadow, source expense, general journal', $output);
        $this->assertStringContainsString('P-46', $output);
        $this->assertStringContainsString('"role":"tax.input"', $output);
        $this->assertStringContainsString('gross', $output);
    }

    public function testRun_SaysSoForAnOperationWithoutRules(): void
    {
        $this->assertStringContainsString('No posting rules for nothing_here.', $this->runCommand('nothing_here'));
    }
}
