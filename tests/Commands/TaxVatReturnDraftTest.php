<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;

class TaxVatReturnDraftTest extends CIUnitTestCase
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

    public function testRun_WritesEveryBoxOfThePeriod(): void
    {
        $this->resetStreamFilterBuffer();

        command('tax:vat_return_draft 2026-10-01 2026-10-31');

        $output = $this->getStreamFilterBuffer();

        $this->assertMatchesRegularExpression('/^\s+1\s+[-0-9.]+\s+[-0-9.]+$/m', $output);
        $this->assertMatchesRegularExpression('/^\s+16\s+0\.00\s+[-0-9.]+$/m', $output);
        $this->assertStringContainsString('(typed by hand)', $output);
        $this->assertStringContainsString('Estimated part of box 3', $output);
    }

    public function testRun_RefusesAPeriodItCannotRead(): void
    {
        $this->resetStreamFilterBuffer();

        command('tax:vat_return_draft 2026-10-31 2026-10-01');

        $this->assertStringContainsString('Give the first and the last day', $this->getStreamFilterBuffer());
    }
}
