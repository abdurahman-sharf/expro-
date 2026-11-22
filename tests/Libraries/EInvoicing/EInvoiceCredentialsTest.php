<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceCredentials;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\KeyVault;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class EInvoiceCredentialsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private string $directory;
    private string $ring;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/expro-credentials-' . bin2hex(random_bytes(4));
        $this->ring      = $this->directory . '/master.key';

        mkdir($this->directory, 0o700);
        file_put_contents($this->ring, KeyVault::newKeyLine('v1') . "\n");
        chmod($this->ring, 0o600);
    }

    protected function tearDown(): void
    {
        Database::connect()->table('einvoice_credentials')->truncate();
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);

        parent::tearDown();
    }

    private function credentials(): EInvoiceCredentials
    {
        return new EInvoiceCredentials(KeyVault::fromFile($this->ring));
    }

    private function storeOne(string $secret = 'secret-one', string $validTo = '2027-11-21 00:00:00'): int
    {
        return $this->credentials()->store(1, 'sandbox', EInvoiceCredentials::COMPLIANCE, 'CERTIFICATE', $secret, 'token-' . $secret, 'private-key-' . $secret, '2026-11-21 00:00:00', $validTo, 'request-1');
    }

    public function testStore_KeepsNoSecretPlainInTheDatabaseAndOpensItForTheActiveCertificate(): void
    {
        $this->storeOne();

        $row    = Database::connect()->table('einvoice_credentials')->get()->getRowArray();
        $active = $this->credentials()->active(1, 'sandbox', EInvoiceCredentials::COMPLIANCE, '2026-12-01 00:00:00');

        $this->assertStringNotContainsString('secret-one', implode('|', array_map('strval', $row)));
        $this->assertSame(['CERTIFICATE', 'secret-one', 'token-secret-one', 'private-key-secret-one'], [$active['certificate'], $active['secret'], $active['token'], $active['private_key']]);
    }

    public function testStore_RetiresTheCertificateItReplacesInOneStep(): void
    {
        $this->storeOne('first');
        $this->storeOne('second');

        $this->assertSame(1, Database::connect()->table('einvoice_credentials')->where('status', 'active')->countAllResults());
        $this->assertSame('second', $this->credentials()->active(1, 'sandbox', EInvoiceCredentials::COMPLIANCE, '2026-12-01 00:00:00')['secret']);
    }

    public function testActive_RefusesACertificateThatRanOutAndWarnsOfOneThatRunsOutSoon(): void
    {
        $this->storeOne('soon', '2026-12-10 00:00:00');

        $this->assertCount(1, $this->credentials()->expiring('2026-12-01 00:00:00', 30));
        $this->assertCount(0, $this->credentials()->expiring('2026-12-01 00:00:00', 7));

        try {
            $this->credentials()->active(1, 'sandbox', EInvoiceCredentials::COMPLIANCE, '2026-12-11 00:00:00');
            $this->fail('A certificate that ran out was given.');
        } catch (EInvoiceException $refusal) {
            $this->assertSame(EInvoiceError::CertificateExpired, $refusal->error);
        }
    }

    public function testRewrapAll_SealsEverySecretWithTheNewestKeyAndTheyStillOpen(): void
    {
        $this->storeOne();
        file_put_contents($this->ring, KeyVault::newKeyLine('v2') . "\n", FILE_APPEND);

        $this->assertSame(1, $this->credentials()->rewrapAll());
        $this->assertStringStartsWith('kv1:v2:', (string) Database::connect()->table('einvoice_credentials')->get()->getRow()->secret_sealed);
        $this->assertSame('secret-one', $this->credentials()->active(1, 'sandbox', EInvoiceCredentials::COMPLIANCE, '2026-12-01 00:00:00')['secret']);
    }
}
