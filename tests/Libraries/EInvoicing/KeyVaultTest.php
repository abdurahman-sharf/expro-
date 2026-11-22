<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\KeyVault;
use CodeIgniter\Test\CIUnitTestCase;

class KeyVaultTest extends CIUnitTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/expro-vault-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);

        parent::tearDown();
    }

    private function ring(string ...$lines): string
    {
        $file = $this->directory . '/master.key';

        file_put_contents($file, implode("\n", $lines) . "\n");
        chmod($file, 0o600);

        return $file;
    }

    private function refusal(callable $action): EInvoiceError
    {
        try {
            $action();
        } catch (EInvoiceException $failure) {
            return $failure->error;
        }

        $this->fail('The vault did not refuse.');
    }

    public function testSeal_KeepsNothingOfTheSecretInTheSealedTextAndOpensItAgain(): void
    {
        $vault  = KeyVault::fromFile($this->ring(KeyVault::newKeyLine('v1')));
        $secret = 'production-secret-' . bin2hex(random_bytes(8));
        $sealed = $vault->seal($secret);

        $this->assertStringNotContainsString($secret, $sealed);
        $this->assertStringNotContainsString(base64_encode($secret), $sealed);
        $this->assertNotSame($sealed, $vault->seal($secret), 'every seal has a key and nonces of its own');
        $this->assertSame($secret, $vault->open($sealed));
    }

    public function testRewrap_MovesASecretToTheNewestKeyAndTheOldKeyStillOpensWhatItSealed(): void
    {
        $first  = KeyVault::newKeyLine('v1');
        $sealed = KeyVault::fromFile($this->ring($first))->seal('the secret');
        $vault  = KeyVault::fromFile($this->ring($first, KeyVault::newKeyLine('v2')));
        $moved  = $vault->rewrap($sealed);

        $this->assertStringStartsWith('kv1:v1:', $sealed);
        $this->assertStringStartsWith('kv1:v2:', $moved);
        $this->assertSame('the secret', $vault->open($sealed));
        $this->assertSame('the secret', $vault->open($moved));
    }

    public function testOpen_RefusesATextSealedWithAKeyItDoesNotHoldOrChanged(): void
    {
        $sealed = KeyVault::fromFile($this->ring(KeyVault::newKeyLine('v1')))->seal('the secret');
        $other  = KeyVault::fromFile($this->ring(KeyVault::newKeyLine('v1')));
        $parts  = explode(':', $sealed);

        $this->assertSame(EInvoiceError::KeyUnreadable, $this->refusal(fn () => $other->open($sealed)));
        $this->assertSame(EInvoiceError::KeyUnreadable, $this->refusal(fn () => $other->open('kv1:v9:' . $parts[2] . ':' . $parts[3])));
        $this->assertSame(EInvoiceError::KeyUnreadable, $this->refusal(fn () => $other->open('not sealed')));
    }

    public function testFromFile_RefusesAFileOthersCanReadOrInsideTheApplication(): void
    {
        $file = $this->ring(KeyVault::newKeyLine('v1'));

        chmod($file, 0o644);

        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal(fn () => KeyVault::fromFile($file)));
        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal(fn () => KeyVault::fromFile(WRITEPATH . 'master.key')));
        $this->assertSame(EInvoiceError::KeyUnreadable, $this->refusal(fn () => KeyVault::fromFile($this->directory . '/missing.key')));
    }
}
