<?php

namespace App\Libraries\Setup;

use App\Libraries\Accounting\LockOutcome;
use App\Libraries\Accounting\NamedLock;
use App\Libraries\Security\PasswordPolicy;
use App\Libraries\Security\SecurityAudit;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * The one way the first owner is made: a person with the code that only the server holds chooses their own name,
 * username and password. The code is checked before anything is written, the place of the owner is taken by one
 * request only and the account, its grants and the end of the code are written in one transaction, so a second
 * request, a failed write or a database restored from before the claim can never leave a second owner or none.
 */
final class OwnerClaim
{
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,32}$/';
    public const NAME_MAX         = 50;

    private BaseConnection $db;
    private InstallState $state;

    public function __construct(?BaseConnection $db = null, ?InstallState $state = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->state = $state ?? new InstallState($this->db);
    }

    /**
     * @param array{code?: string, first_name?: string, last_name?: string, username?: string, email?: string, password?: string, password_confirm?: string} $input
     *
     * @return int The person who now owns the installation.
     *
     * @throws OwnerClaimException
     */
    public function claim(array $input, string $address): int
    {
        if (! $this->state->needsOwner()) {
            throw new OwnerClaimException('closed');
        }

        $fields = $this->validated($input);

        $lock  = new NamedLock($this->db, 0);
        $name  = 'ospos_owner_claim_' . substr(sha1($this->db->getDatabase()), 0, 10);
        $taken = $lock->take($name);

        if ($taken !== LockOutcome::Taken) {
            throw new OwnerClaimException('busy');
        }

        try {
            $personId = $this->write($fields, (string) ($input['code'] ?? ''), $address);
        } finally {
            $lock->release($name);
        }

        $this->state->forgetCode();
        (new SecurityAudit($this->db))->ownerClaimed($personId, $address);

        return $personId;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{first_name: string, last_name: string, username: string, email: string, password: string}
     *
     * @throws OwnerClaimException
     */
    private function validated(array $input): array
    {
        $first    = trim((string) ($input['first_name'] ?? ''));
        $last     = trim((string) ($input['last_name'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $email    = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($first === '' || $last === '' || mb_strlen($first) > self::NAME_MAX || mb_strlen($last) > self::NAME_MAX) {
            throw new OwnerClaimException('name_required');
        }

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new OwnerClaimException('username_invalid');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new OwnerClaimException('email_invalid');
        }

        $violation = PasswordPolicy::violation($password, $username);

        if ($violation !== null) {
            throw new OwnerClaimException('password_policy', ['line' => $violation]);
        }

        if (! hash_equals($password, (string) ($input['password_confirm'] ?? ''))) {
            throw new OwnerClaimException('password_mismatch');
        }

        return ['first_name' => $first, 'last_name' => $last, 'username' => $username, 'email' => $email, 'password' => $password];
    }

    /**
     * @param array{first_name: string, last_name: string, username: string, email: string, password: string} $fields
     *
     * @throws OwnerClaimException
     */
    private function write(array $fields, string $code, string $address): int
    {
        if (! $this->state->codeMatches($code)) {
            (new SecurityAudit($this->db))->ownerClaimFailed($address);

            throw new OwnerClaimException('code_wrong');
        }

        $this->db->transBegin();

        try {
            if (! $this->state->takeOwnerPlace()) {
                throw new OwnerClaimException('closed');
            }

            $personId = InstallState::OWNER_ID;
            $taken    = $this->db->table('employees')->where('username', $fields['username'])->where('person_id !=', $personId)->countAllResults();

            if ($taken > 0) {
                throw new OwnerClaimException('username_taken');
            }

            $this->db->table('people')->where('person_id', $personId)->update([
                'first_name' => $fields['first_name'],
                'last_name'  => $fields['last_name'],
                'email'      => $fields['email'],
            ]);

            $this->db->table('employees')->where('person_id', $personId)->update([
                'username'     => $fields['username'],
                'password'     => password_hash($fields['password'], PASSWORD_DEFAULT),
                'hash_version' => 2,
                'deleted'      => 0,
            ]);

            (new OwnerPermissions($this->db))->grantEverything($personId);

            $this->db->transCommit();
        } catch (Throwable $failure) {
            $this->db->transRollback();

            throw $failure;
        }

        return $personId;
    }
}
