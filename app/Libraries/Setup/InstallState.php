<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Where a database stands in its first setup, written down when the database is made and never worked out from
 * what it holds. A database that was made before this existed has no entry and counts as set up, so a database that
 * is upgraded, or one that is restored from a backup, keeps the state it had. Only the migration that builds
 * an empty database writes the first entry, and the code that lets the first owner in is kept as a hash.
 */
final class InstallState
{
    public const KEY           = 'install_state';
    public const ID_KEY        = 'install_id';
    public const CODE_KEY      = 'install_setup_code_hash';
    public const PENDING_OWNER = 'pending_owner';
    public const PENDING_SETUP = 'pending_setup';
    public const COMPLETE      = 'complete';
    public const OWNER_ID      = 1;
    public const CODE_FILE     = 'setup/owner-code.txt';
    private const ALPHABET     = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH  = 16;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function state(): string
    {
        $row = $this->db->table('app_config')->where('key', self::KEY)->get()->getRow();

        return $row === null || $row->value === '' ? self::COMPLETE : (string) $row->value;
    }

    public function needsOwner(): bool
    {
        return $this->state() === self::PENDING_OWNER;
    }

    public function needsSetup(): bool
    {
        return $this->state() === self::PENDING_SETUP;
    }

    public function isComplete(): bool
    {
        return $this->state() === self::COMPLETE;
    }

    public function installId(): string
    {
        return (string) ($this->db->table('app_config')->where('key', self::ID_KEY)->get()->getRow()->value ?? '');
    }

    /**
     * Marks an empty database as waiting for its owner: the account the schema script plants gets a password nobody
     * can use, and a code that only the person with access to the server can read is made for the first sign-up.
     *
     * @return string The code, in the form it is typed.
     */
    public function initializeFreshInstall(): string
    {
        $fromEnvironment = (string) env('setup.code', '');
        $code            = $fromEnvironment !== '' ? $this->normalize($fromEnvironment) : $this->generateCode();

        $this->put(self::KEY, self::PENDING_OWNER);
        $this->put(self::ID_KEY, bin2hex(random_bytes(16)));
        $this->put(self::CODE_KEY, password_hash($code, PASSWORD_DEFAULT));

        $this->db->table('employees')->where('person_id', self::OWNER_ID)->update([
            'password'     => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'hash_version' => 2,
        ]);

        if ($fromEnvironment === '') {
            $this->writeCodeFile($code);
        }

        return $this->format($code);
    }

    /**
     * A new code for a database that still waits for its owner, which makes every older code useless.
     */
    public function renewCode(): ?string
    {
        if (! $this->needsOwner()) {
            return null;
        }

        $code = $this->generateCode();

        $this->put(self::CODE_KEY, password_hash($code, PASSWORD_DEFAULT));
        $this->writeCodeFile($code);

        return $this->format($code);
    }

    public function codeMatches(string $typed): bool
    {
        $typed = $this->normalize($typed);

        if ($typed === '') {
            return false;
        }

        $fromEnvironment = (string) env('setup.code', '');

        if ($fromEnvironment !== '') {
            return hash_equals($this->normalize($fromEnvironment), $typed);
        }

        $hash = (string) ($this->db->table('app_config')->where('key', self::CODE_KEY)->get()->getRow()->value ?? '');

        return $hash !== '' && password_verify($typed, $hash);
    }

    /**
     * Takes the place of the owner for one request only, and fails unless nobody took it before.
     */
    public function takeOwnerPlace(): bool
    {
        $this->db->table('app_config')->where('key', self::KEY)->where('value', self::PENDING_OWNER)->update(['value' => self::PENDING_SETUP]);

        return $this->db->affectedRows() === 1;
    }

    public function markSetUp(): bool
    {
        $this->db->table('app_config')->where('key', self::KEY)->where('value', self::PENDING_SETUP)->update(['value' => self::COMPLETE]);

        return $this->db->affectedRows() === 1;
    }

    public function forgetCode(): void
    {
        $this->db->table('app_config')->where('key', self::CODE_KEY)->delete();

        $file = WRITEPATH . self::CODE_FILE;

        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    private function generateCode(): string
    {
        $code = '';

        for ($index = 0; $index < self::CODE_LENGTH; $index++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    private function writeCodeFile(string $code): void
    {
        $directory = dirname(WRITEPATH . self::CODE_FILE);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            log_message('error', 'The folder for the owner setup code could not be made.');

            return;
        }

        $file = WRITEPATH . self::CODE_FILE;

        if (@file_put_contents($file, "Owner setup code: {$this->format($code)}\nIt works until the owner account is created.\n") === false) {
            log_message('error', 'The owner setup code could not be written.');

            return;
        }

        @chmod($file, 0600);
    }

    private function put(string $key, string $value): void
    {
        $exists = $this->db->table('app_config')->where('key', $key)->countAllResults() > 0;

        if ($exists) {
            $this->db->table('app_config')->where('key', $key)->update(['value' => $value]);

            return;
        }

        $this->db->table('app_config')->insert(['key' => $key, 'value' => $value]);
    }
}
