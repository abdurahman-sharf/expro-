<?php

namespace Tests\Support;

use App\Libraries\Setup\InstallState;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Puts a test database in the state of an installation that waits for its owner and puts the first account back
 * as it was, because the first account is the one almost every other test signs in with.
 */
trait InstallStateFixtureTrait
{
    /** @var array{people: ?array<string, mixed>, employee: ?array<string, mixed>, grants: list<array<string, mixed>>, config: array<string, ?string>}|null */
    private ?array $installSnapshot = null;

    protected function installDb(): BaseConnection
    {
        return Database::connect();
    }

    protected function snapshotInstall(): void
    {
        $db = $this->installDb();

        $this->installSnapshot = [
            'people'   => $db->table('people')->where('person_id', InstallState::OWNER_ID)->get()->getRowArray(),
            'employee' => $db->table('employees')->where('person_id', InstallState::OWNER_ID)->get()->getRowArray(),
            'grants'   => $db->table('grants')->where('person_id', InstallState::OWNER_ID)->get()->getResultArray(),
            'config'   => [],
        ];

        foreach ([InstallState::KEY, InstallState::ID_KEY, InstallState::CODE_KEY] as $key) {
            $row = $db->table('app_config')->where('key', $key)->get()->getRow();

            $this->installSnapshot['config'][$key] = $row === null ? null : (string) $row->value;
        }
    }

    protected function restoreInstall(): void
    {
        if ($this->installSnapshot === null) {
            return;
        }

        $db = $this->installDb();

        $db->table('people')->where('person_id', InstallState::OWNER_ID)->update($this->installSnapshot['people']);
        $db->table('employees')->where('person_id', InstallState::OWNER_ID)->update($this->installSnapshot['employee']);
        $db->table('grants')->where('person_id', InstallState::OWNER_ID)->delete();

        if ($this->installSnapshot['grants'] !== []) {
            $db->table('grants')->insertBatch($this->installSnapshot['grants']);
        }

        foreach ($this->installSnapshot['config'] as $key => $value) {
            if ($value === null) {
                $db->table('app_config')->where('key', $key)->delete();
            } else {
                $db->table('app_config')->where('key', $key)->update(['value' => $value]);
            }
        }

        $db->table('employees')->whereIn('username', ['owner.one', 'taken.name'])->where('person_id !=', InstallState::OWNER_ID)->delete();

        $file = WRITEPATH . InstallState::CODE_FILE;

        if (is_file($file)) {
            unlink($file);
        }

        $this->installSnapshot = null;
    }

    /**
     * @return string The code the first sign-up asks for.
     */
    protected function makeInstallFresh(): string
    {
        return (new InstallState($this->installDb()))->initializeFreshInstall();
    }

    protected function setInstallState(string $state): void
    {
        $db  = $this->installDb();
        $row = $db->table('app_config')->where('key', InstallState::KEY)->get()->getRow();

        if ($row === null) {
            $db->table('app_config')->insert(['key' => InstallState::KEY, 'value' => $state]);

            return;
        }

        $db->table('app_config')->where('key', InstallState::KEY)->update(['value' => $state]);
    }
}
