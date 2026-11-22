<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;

class EInvoiceDispatchTest extends CIUnitTestCase
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

    public function testRun_SendsNothingAndSaysWhyAndHowManyDocumentsWait(): void
    {
        $this->resetStreamFilterBuffer();

        command('einvoice:dispatch');

        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('Nothing is sent to ZATCA: testing, network_off, environment_not_approved, integration_off. Documents waiting: 0.', $output);
        $this->assertSame(0, Database::connect()->table('einvoice_submissions')->countAllResults(), 'not a single attempt');
    }
}
