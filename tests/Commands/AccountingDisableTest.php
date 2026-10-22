<?php

namespace Tests\Commands;

use App\Libraries\Accounting\AccountingSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingDisableTest extends CIUnitTestCase
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

    public function testRun_TurnsAccountingOffAndKeepsPostedJournals(): void
    {
        $this->clearJournals();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        (new \App\Libraries\Accounting\PostingService())->post($this->balancedDraft());
        $this->setConfigValue('accounting_enabled', '1');

        $this->resetStreamFilterBuffer();
        command('accounting:disable');

        $this->assertStringContainsString('Accounting is disabled', $this->getStreamFilterBuffer());
        $this->assertFalse((new AccountingSettings())->isEnabled());
        $this->assertSame(1, $this->countRows('accounting_journals'));
    }
}
