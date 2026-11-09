<?php

namespace App\Libraries\Integrations;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Checks the key a call presents. Whatever is wrong with a key, the caller is told only that it is not
 * accepted, and the reason goes to the audit log together with the prefix of the key and the address of the
 * caller, never the secret nor anything of it. The failed calls of one address are written one by one up to a
 * limit in a minute, then once as throttled, so that a flood cannot fill the log. A key that passes is held
 * to its calls a minute, and its last use is written.
 */
final class ApiAuthenticator
{
    public const FAILURES_AUDITED = 20;

    private BaseConnection $db;
    private ApiKeys $keys;
    private ApiRateLimiter $limiter;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?ApiKeys $keys = null, ?ApiRateLimiter $limiter = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->keys    = $keys ?? new ApiKeys($this->db);
        $this->limiter = $limiter ?? new ApiRateLimiter($this->db);
        $this->audit   = new AuditLog($this->db);
    }

    public function authenticate(?string $presented, string $address): ApiAuthResult
    {
        $presented = $presented === null ? '' : trim($presented);

        if ($presented === '') {
            return $this->failed(ApiFailure::Missing, null, null, $address);
        }

        if (preg_match(ApiKeys::KEY_FORMAT, $presented, $parts) !== 1) {
            return $this->failed(ApiFailure::Malformed, null, null, $address);
        }

        [, $prefix, $secret] = $parts;

        $row = $this->keys->rowOfPrefix($prefix);

        if ($row === null) {
            // Compared anyway, so that a prefix nobody holds takes as long to refuse as a wrong secret.
            hash_equals(ApiKeys::hashOf('unknown'), ApiKeys::hashOf($secret));

            return $this->failed(ApiFailure::Unknown, $prefix, null, $address);
        }

        if (! hash_equals((string) $row->secret_hash, ApiKeys::hashOf($secret))) {
            return $this->failed(ApiFailure::Unknown, $prefix, (int) $row->key_id, $address);
        }

        $key = $this->keys->keyOf($row);

        if ($key->revoked) {
            return $this->failed(ApiFailure::Revoked, $prefix, $key->id, $address);
        }

        if ($key->isExpired(date('Y-m-d H:i:s', $this->limiter->now()))) {
            return $this->failed(ApiFailure::Expired, $prefix, $key->id, $address);
        }

        if ($this->limiter->hit('key:' . $key->id) > $key->rateLimit) {
            log_message('warning', 'The API key {prefix} went over its {limit} calls a minute.', ['prefix' => $prefix, 'limit' => $key->rateLimit]);

            return new ApiAuthResult(null, ApiFailure::RateLimited, $this->limiter->retryAfter());
        }

        $this->keys->touch($key->id, date('Y-m-d H:i:s', $this->limiter->now()));

        return new ApiAuthResult($key, null);
    }

    private function failed(ApiFailure $failure, ?string $prefix, ?int $keyId, string $address): ApiAuthResult
    {
        $hits = $this->limiter->hit('fail:' . substr(hash('sha256', $address), 0, 32));

        log_message('warning', 'An API call was not accepted ({reason}) for the key {prefix} from {address}.', ['reason' => $failure->value, 'prefix' => $prefix ?? '-', 'address' => $address]);

        if ($hits <= self::FAILURES_AUDITED) {
            $this->audit->write('api_key', $keyId, 'api_auth_failed', null, JournalOrigin::System, ['reason' => $failure->value, 'prefix' => $prefix, 'address' => $address]);
        } elseif ($hits === self::FAILURES_AUDITED + 1) {
            $this->audit->write('api_key', null, 'api_auth_throttled', null, JournalOrigin::System, ['address' => $address, 'audited' => self::FAILURES_AUDITED]);
        }

        return new ApiAuthResult(null, $failure);
    }
}
