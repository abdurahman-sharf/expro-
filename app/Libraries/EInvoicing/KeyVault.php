<?php

namespace App\Libraries\EInvoicing;

use SensitiveParameter;

/**
 * Keeps the secrets of the certificates sealed (§4.8, D-15): each secret is sealed with a key of its own, and that
 * key is sealed with the master key, which lives only in a file outside the application, the web root, the
 * writable directory and the database, readable by its owner alone, never in .env or the settings. The file holds
 * a ring of master keys, one a line as `version:base64`, the last of them the one that seals; every sealed text
 * names the version it was sealed with, so a key added later seals the new secrets while the old ones still open,
 * and rewrap() moves a sealed text to the newest key without the secret leaving memory.
 */
final class KeyVault
{
    private const PREFIX = 'kv1';

    /**
     * @var array<string, string> Master keys by version, the newest last.
     */
    private array $ring;

    /**
     * @param array<string, string> $ring
     */
    private function __construct(#[SensitiveParameter] array $ring)
    {
        $this->ring = $ring;
    }

    /**
     * @param list<string>|null $protected The directories the file may not be in, those of the application when none is given.
     *
     * @throws EInvoiceException
     */
    public static function fromFile(string $path, ?array $protected = null): self
    {
        $file = KeyPath::check($path, $protected);

        if (! is_file($file) || ! is_readable($file)) {
            throw new EInvoiceException(EInvoiceError::KeyUnreadable, 'The master key cannot be read.');
        }

        if (DIRECTORY_SEPARATOR === '/' && (fileperms($file) & 0o077) !== 0) {
            throw new EInvoiceException(EInvoiceError::KeyPathUnsafe, 'The master key is readable by others than its owner.', ['field' => $path]);
        }

        $ring = [];

        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9]{1,16}):([A-Za-z0-9+\/=]+)$/', trim($line), $match) !== 1) {
                continue;
            }

            $key = base64_decode($match[2], true);

            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                $ring[$match[1]] = $key;
            }
        }

        if ($ring === []) {
            throw new EInvoiceException(EInvoiceError::KeyUnreadable, 'The master key file holds no key.');
        }

        return new self($ring);
    }

    /**
     * A line of a key ring with a new master key, to be added at the end of the file.
     */
    public static function newKeyLine(string $version): string
    {
        return $version . ':' . base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function seal(#[SensitiveParameter] string $secret): string
    {
        $version = (string) array_key_last($this->ring);
        $dataKey = sodium_crypto_secretbox_keygen();
        $inner   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $outer   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $body    = sodium_crypto_secretbox($secret, $inner, $dataKey);
        $wrapped = sodium_crypto_secretbox($dataKey, $outer, $this->ring[$version]);

        sodium_memzero($dataKey);

        return implode(':', [self::PREFIX, $version, base64_encode($outer . $wrapped), base64_encode($inner . $body)]);
    }

    /**
     * @throws EInvoiceException
     */
    public function open(string $sealed): string
    {
        [$version, $wrapped, $body] = $this->parts($sealed);

        $dataKey = sodium_crypto_secretbox_open(substr($wrapped, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($wrapped, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->ring[$version]);
        $secret  = $dataKey === false ? false : sodium_crypto_secretbox_open(substr($body, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($body, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $dataKey);

        if (is_string($dataKey)) {
            sodium_memzero($dataKey);
        }

        if ($secret === false) {
            throw new EInvoiceException(EInvoiceError::KeyUnreadable, 'A sealed secret does not open with the master key.');
        }

        return $secret;
    }

    /**
     * The same secret sealed with the newest master key.
     *
     * @throws EInvoiceException
     */
    public function rewrap(string $sealed): string
    {
        $secret = $this->open($sealed);
        $again  = $this->seal($secret);

        sodium_memzero($secret);

        return $again;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     *
     * @throws EInvoiceException
     */
    private function parts(string $sealed): array
    {
        $parts   = explode(':', $sealed);
        $wrapped = isset($parts[2]) ? base64_decode($parts[2], true) : false;
        $body    = isset($parts[3]) ? base64_decode($parts[3], true) : false;

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX || ! isset($this->ring[$parts[1]]) || $wrapped === false || $body === false) {
            throw new EInvoiceException(EInvoiceError::KeyUnreadable, 'A sealed secret is not one this vault can open.');
        }

        return [$parts[1], $wrapped, $body];
    }
}
