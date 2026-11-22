<?php

namespace Tests\Commands;

use App\Libraries\EInvoicing\EInvoiceCredentials;
use App\Libraries\EInvoicing\KeyVault;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;

class EInvoiceRotateKeyTest extends CIUnitTestCase
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

    public function testRun_AddsAKeyAndSealsEveryCertificateWithIt(): void
    {
        $directory = sys_get_temp_dir() . '/expro-rotate-' . bin2hex(random_bytes(4));
        $ring      = $directory . '/master.key';

        mkdir($directory, 0o700);
        file_put_contents($ring, KeyVault::newKeyLine('v1') . "\n");
        chmod($ring, 0o600);

        try {
            (new EInvoiceCredentials(KeyVault::fromFile($ring)))->store(1, 'sandbox', EInvoiceCredentials::COMPLIANCE, 'CERT', 's', 't', 'k', '2026-11-21 00:00:00', '2027-11-21 00:00:00');
            $this->resetStreamFilterBuffer();

            command('einvoice:rotate_key ' . $ring);

            $this->assertStringContainsString('Master key v2 added; 1 certificates sealed with it.', $this->getStreamFilterBuffer());
            $this->assertStringStartsWith('kv1:v2:', (string) Database::connect()->table('einvoice_credentials')->get()->getRow()->secret_sealed);
            $this->assertSame('s', (new EInvoiceCredentials(KeyVault::fromFile($ring)))->active(1, 'sandbox', EInvoiceCredentials::COMPLIANCE, '2026-12-01 00:00:00')['secret']);
        } finally {
            Database::connect()->table('einvoice_credentials')->truncate();
            array_map('unlink', glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }
}
