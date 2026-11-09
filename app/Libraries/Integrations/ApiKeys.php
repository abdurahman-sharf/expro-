<?php

namespace App\Libraries\Integrations;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;

/**
 * The keys of the read-only API. A key is a prefix that finds it and a secret that proves it, and only a hash of
 * the secret is stored: the whole key is handed over once, when it is made, and nothing else ever holds it, not
 * the audit log, not the application log and not the table. A key carries the scopes it may read, may be limited
 * to one branch, has a number of calls it may make in a minute, may expire, and is taken away by revoking it,
 * which cannot be undone. Making a key and revoking one need the sensitive permission and every branch.
 */
final class ApiKeys
{
    use RunsInTransaction;

    public const KEY_FORMAT   = '/^exk_([0-9a-f]{8})_([0-9a-f]{64})$/';
    public const DEFAULT_RATE = 60;
    public const MAX_RATE     = 6000;
    public const MAX_NAME     = 100;
    private const ATTEMPTS    = 5;

    private BaseConnection $db;
    private AuditLog $audit;
    private IntegrationAccess $access;

    public function __construct(?BaseConnection $db = null, ?IntegrationAccess $access = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->audit  = new AuditLog($this->db);
        $this->access = $access ?? new IntegrationAccess();
    }

    public static function hashOf(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * @return list<ApiKey> Newest first.
     */
    public function all(): array
    {
        return array_map($this->made(...), $this->db->table('api_keys')->where('company_id', CompanyContext::id())->orderBy('key_id', 'DESC')->get()->getResult());
    }

    public function find(int $keyId): ?ApiKey
    {
        $row = $this->db->table('api_keys')->where(['company_id' => CompanyContext::id(), 'key_id' => $keyId])->get()->getRow();

        return $row === null ? null : $this->made($row);
    }

    /**
     * The stored row of a key by the prefix it was handed over with, secret hash included, for the one place that
     * checks a secret.
     */
    public function rowOfPrefix(string $prefix): ?object
    {
        return $this->db->table('api_keys')->where(['company_id' => CompanyContext::id(), 'key_prefix' => $prefix])->get()->getRow();
    }

    public function keyOf(object $row): ApiKey
    {
        return $this->made($row);
    }

    public function touch(int $keyId, string $at): void
    {
        $this->db->table('api_keys')->where('key_id', $keyId)->update(['last_used_at' => $at]);
    }

    /**
     * @param array{name?: mixed, scopes?: mixed, branch_id?: mixed, rate_limit?: mixed, expires_on?: mixed} $input
     *
     * @return array{key: ApiKey, secret: string} The secret is the whole key, shown once.
     *
     * @throws BranchException|IntegrationException
     */
    public function create(array $input, ?int $employeeId = null): array
    {
        $this->access->requireKeys($employeeId);

        $name      = $this->name($input['name'] ?? '');
        $scopes    = $this->scopes($input['scopes'] ?? []);
        $branchId  = $this->branch($input['branch_id'] ?? null);
        $rate      = $this->rate($input['rate_limit'] ?? null);
        $expiresAt = $this->expiry($input['expires_on'] ?? null);

        return $this->transactional(function () use ($name, $scopes, $branchId, $rate, $expiresAt, $employeeId): array {
            $prefix = $this->freshPrefix();
            $secret = bin2hex(random_bytes(32));

            $this->db->table('api_keys')->insert([
                'company_id'  => CompanyContext::id(),
                'key_prefix'  => $prefix,
                'secret_hash' => self::hashOf($secret),
                'name'        => $name,
                'scopes'      => implode(',', array_map(static fn (ApiScope $scope): string => $scope->value, $scopes)),
                'branch_id'   => $branchId,
                'rate_limit'  => $rate,
                'expires_at'  => $expiresAt,
                'created_by'  => $employeeId,
            ]);

            $keyId = (int) $this->db->insertID();

            $this->audit->write('api_key', $keyId, 'api_key_created', $employeeId, JournalOrigin::User, [
                'prefix'     => $prefix,
                'name'       => $name,
                'scopes'     => array_map(static fn (ApiScope $scope): string => $scope->value, $scopes),
                'branch_id'  => $branchId,
                'rate_limit' => $rate,
                'expires_at' => $expiresAt,
            ], $branchId);

            return ['key' => $this->find($keyId) ?? throw new IntegrationException(IntegrationError::ApiKeyNotFound), 'secret' => 'exk_' . $prefix . '_' . $secret];
        });
    }

    /**
     * @throws BranchException|IntegrationException
     */
    public function revoke(int $keyId, ?int $employeeId = null): void
    {
        $this->access->requireKeys($employeeId);

        $this->transactional(function () use ($keyId, $employeeId): void {
            $row = $this->db->query('SELECT * FROM ' . $this->db->prefixTable('api_keys') . ' WHERE company_id = ? AND key_id = ? FOR UPDATE', [CompanyContext::id(), $keyId])->getRow();

            if ($row === null) {
                throw new IntegrationException(IntegrationError::ApiKeyNotFound, "The key {$keyId} does not exist.", ['id' => $keyId]);
            }

            if ((int) $row->revoked === 1) {
                throw new IntegrationException(IntegrationError::ApiKeyRevoked, "The key {$keyId} is revoked already.", ['id' => $keyId]);
            }

            $this->db->table('api_keys')->where('key_id', $keyId)->update(['revoked' => 1, 'revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => $employeeId]);
            $this->audit->write('api_key', $keyId, 'api_key_revoked', $employeeId, JournalOrigin::User, ['prefix' => (string) $row->key_prefix, 'name' => (string) $row->name], $row->branch_id === null ? null : (int) $row->branch_id);
        });
    }

    private function made(object $row): ApiKey
    {
        $scopes = [];

        foreach (explode(',', (string) $row->scopes) as $value) {
            $scope = ApiScope::tryFrom($value);

            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return new ApiKey(
            (int) $row->key_id,
            (string) $row->key_prefix,
            (string) $row->name,
            $scopes,
            $row->branch_id === null ? null : (int) $row->branch_id,
            (int) $row->rate_limit,
            $row->expires_at === null ? null : (string) $row->expires_at,
            (int) $row->revoked === 1,
            $row->revoked_at === null ? null : (string) $row->revoked_at,
            $row->last_used_at === null ? null : (string) $row->last_used_at,
            $row->created_by === null ? null : (int) $row->created_by,
            (string) $row->created_at,
        );
    }

    /**
     * @throws IntegrationException
     */
    private function name(mixed $value): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/\p{C}/u', $name) === 1) {
            throw new IntegrationException(IntegrationError::ApiKeyName, 'A key needs a short plain name.', ['max' => self::MAX_NAME]);
        }

        return $name;
    }

    /**
     * @return list<ApiScope>
     *
     * @throws IntegrationException
     */
    private function scopes(mixed $value): array
    {
        $asked = is_array($value) ? array_values(array_unique(array_map('strval', $value))) : [];
        $found = [];

        foreach ($asked as $text) {
            $found[] = ApiScope::tryFrom($text) ?? throw new IntegrationException(IntegrationError::ApiKeyScopes, "{$text} is not a scope of the API.", ['name' => $text]);
        }

        if ($found === []) {
            throw new IntegrationException(IntegrationError::ApiKeyScopes, 'A key needs at least one scope.', ['name' => '']);
        }

        return $found;
    }

    /**
     * @throws IntegrationException
     */
    private function branch(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return null;
        }

        $branchId = filter_var($value, FILTER_VALIDATE_INT);
        $found    = $branchId !== false && $this->db->table('branches')->where(['branch_id' => $branchId, 'company_id' => CompanyContext::id(), 'active' => 1])->countAllResults() > 0;

        if (! $found) {
            throw new IntegrationException(IntegrationError::ApiKeyBranch, 'The branch is not an active branch of the company.', ['id' => (string) $value]);
        }

        return $branchId;
    }

    /**
     * @throws IntegrationException
     */
    private function rate(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_RATE;
        }

        $rate = filter_var($value, FILTER_VALIDATE_INT);

        if ($rate === false || $rate < 1 || $rate > self::MAX_RATE) {
            throw new IntegrationException(IntegrationError::ApiKeyRate, 'The calls a minute are a whole number from 1 to ' . self::MAX_RATE . '.', ['max' => self::MAX_RATE]);
        }

        return $rate;
    }

    /**
     * @throws IntegrationException
     */
    private function expiry(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) $value));

        if ($day === false || $day->format('Y-m-d') !== trim((string) $value) || $day->format('Y-m-d') < date('Y-m-d')) {
            throw new IntegrationException(IntegrationError::ApiKeyExpiry, 'The last day of a key is a day that has not passed.', ['date' => (string) $value]);
        }

        return $day->format('Y-m-d') . ' 23:59:59';
    }

    private function freshPrefix(): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $prefix = bin2hex(random_bytes(4));

            if ($this->rowOfPrefix($prefix) === null) {
                return $prefix;
            }
        }

        throw new RuntimeException('A free prefix for the key could not be found.');
    }
}
