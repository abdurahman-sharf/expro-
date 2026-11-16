<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * The owner of a throwaway development or test installation made from the command line, for the scripts that start
 * from an empty database and have nobody to type the setup code. It does what a sign-up does, without the code and
 * without the password policy, and it refuses to run anywhere that is not a development or a testing environment.
 */
final class DevOwner
{
    private BaseConnection $db;
    private InstallState $state;

    public function __construct(?BaseConnection $db = null, ?InstallState $state = null, private readonly string $environment = ENVIRONMENT)
    {
        $this->db    = $db ?? Database::connect();
        $this->state = $state ?? new InstallState($this->db);
    }

    /**
     * @throws RuntimeException
     */
    public function create(string $username, string $password): int
    {
        if (! in_array($this->environment, ['development', 'testing'], true)) {
            throw new RuntimeException('This command only runs when CI_ENVIRONMENT is development or testing.');
        }

        if (preg_match(OwnerClaim::USERNAME_PATTERN, $username) !== 1) {
            throw new RuntimeException('The username needs 3 to 32 letters, digits, dots, dashes or underscores.');
        }

        if ($password === '') {
            throw new RuntimeException('A password is needed, with --password or the DEV_OWNER_PASSWORD variable.');
        }

        if (! $this->state->needsOwner()) {
            throw new RuntimeException('This installation is not waiting for its owner.');
        }

        $this->db->transBegin();

        try {
            if (! $this->state->takeOwnerPlace()) {
                throw new RuntimeException('This installation is not waiting for its owner.');
            }

            $personId = InstallState::OWNER_ID;

            $this->db->table('employees')->where('person_id', $personId)->update([
                'username'     => $username,
                'password'     => password_hash($password, PASSWORD_DEFAULT),
                'hash_version' => 2,
                'deleted'      => 0,
            ]);

            (new OwnerPermissions($this->db))->grantEverything($personId);

            if (! $this->state->markSetUp()) {
                throw new RuntimeException('The installation could not be marked as set up.');
            }

            $this->db->transCommit();
        } catch (Throwable $failure) {
            $this->db->transRollback();

            throw $failure;
        }

        $this->state->forgetCode();

        return $personId;
    }
}
