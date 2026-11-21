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

    public function testRun_SendsNothingAndSaysHowManyDocumentsWait(): void
    {
        $this->resetStreamFilterBuffer();

        command('einvoice:dispatch');

        $this->assertStringContainsString('nothing is sent. Documents waiting: 0.', $this->getStreamFilterBuffer());
    }
}
