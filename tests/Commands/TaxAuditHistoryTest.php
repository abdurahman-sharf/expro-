<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;

class TaxAuditHistoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        Database::connect()->table('company_tax_profile')->update(['ksa_mode' => 0, 'ksa_mode_effective_at' => null]);

        parent::tearDown();
    }

    public function testRun_SaysThereIsNothingToAuditWhileTheRulesWereNeverTurnedOn(): void
    {
        $this->resetStreamFilterBuffer();

        command('tax:audit_history');

        $this->assertStringContainsString('never turned on', $this->getStreamFilterBuffer());
    }

    public function testRun_ListsTheFindingsUnderTheRules(): void
    {
        Database::connect()->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);
        $this->resetStreamFilterBuffer();

        command('tax:audit_history');

        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('sales_without_document', $output);
        $this->assertStringContainsString('returns_without_origin', $output);
        $this->assertStringContainsString('sales_before_the_rules', $output);
    }
}
