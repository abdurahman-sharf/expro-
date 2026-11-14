<?php

namespace App\Libraries\Security;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Integrations\ApiRateLimiter;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * What the audit log keeps of who signed in, who signed out, whose password changed, who was created or deleted,
 * what an employee was given or lost and which of the settings that guard the application changed. A failed
 * sign-in is written once in a minute for each name and address, so that a flood cannot fill the log, and the
 * name is kept escaped because the audit screens show it as it is. A settings entry holds the names of the
 * settings, never their values.
 */
final class SecurityAudit
{
    public const SESSION_ENTITY  = 'employee_session';
    public const EMPLOYEE_ENTITY = 'employee';
    public const SETTINGS_ENTITY = 'security_settings';
    public const NAME_LENGTH     = 64;
    public const SETTINGS        = [
        'gcaptcha_enable', 'gcaptcha_secret_key', 'gcaptcha_site_key', 'image_allowed_types', 'enforce_privacy',
        'smtp_host', 'smtp_user', 'smtp_pass', 'smtp_port', 'smtp_crypto', 'protocol', 'mailpath',
        'msg_uid', 'msg_pwd', 'msg_src', 'mailchimp_api_key',
    ];
    private const BOOLEAN_SETTINGS = ['gcaptcha_enable', 'enforce_privacy'];

    private BaseConnection $db;
    private AuditLog $audit;
    private ApiRateLimiter $limiter;

    public function __construct(?BaseConnection $db = null, ?ApiRateLimiter $limiter = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->audit   = new AuditLog($this->db);
        $this->limiter = $limiter ?? new ApiRateLimiter($this->db);
    }

    public function loginSucceeded(int $personId, string $address): void
    {
        $this->record(self::SESSION_ENTITY, $personId, 'employee_login_succeeded', $personId, JournalOrigin::User, ['address' => $address]);
    }

    public function loginFailed(string $username, string $address): void
    {
        $this->attempt(function () use ($username, $address): void {
            if ($this->limiter->hit('login-failed:' . substr(hash('sha256', mb_strtolower($username) . '|' . $address), 0, 32)) > 1) {
                return;
            }

            $this->audit->write(self::SESSION_ENTITY, null, 'employee_login_failed', null, JournalOrigin::System, ['username' => $this->printable($username), 'address' => $address]);
        });
    }

    public function logout(int $personId): void
    {
        $this->record(self::SESSION_ENTITY, $personId, 'employee_logout', $personId, JournalOrigin::User);
    }

    public function passwordChanged(?int $actorId, int $targetId): void
    {
        $this->record(self::EMPLOYEE_ENTITY, $targetId, $actorId === $targetId ? 'employee_password_changed' : 'employee_password_reset', $actorId, JournalOrigin::User);
    }

    public function employeeCreated(?int $actorId, int $targetId, string $username): void
    {
        $this->record(self::EMPLOYEE_ENTITY, $targetId, 'employee_created', $actorId, JournalOrigin::User, ['username' => $this->printable($username)]);
    }

    public function employeeDeleted(?int $actorId, int $targetId): void
    {
        $this->record(self::EMPLOYEE_ENTITY, $targetId, 'employee_deleted', $actorId, JournalOrigin::User);
    }

    public function usernameChanged(?int $actorId, int $targetId, string $before, string $after): void
    {
        if ($before !== $after) {
            $this->record(self::EMPLOYEE_ENTITY, $targetId, 'employee_username_changed', $actorId, JournalOrigin::User, ['before' => $this->printable($before), 'after' => $this->printable($after)]);
        }
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    public function permissionsChanged(?int $actorId, int $targetId, array $before, array $after): void
    {
        $added   = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== [] || $removed !== []) {
            $this->record(self::EMPLOYEE_ENTITY, $targetId, 'employee_permissions_changed', $actorId, JournalOrigin::User, ['added' => $added, 'removed' => $removed]);
        }
    }

    /**
     * @param array<string, mixed> $before The settings as they were.
     * @param array<string, mixed> $after  The settings as they were saved, of which only the ones that guard the application count.
     */
    public function settingsChanged(?int $actorId, array $before, array $after): void
    {
        $changed = [];

        foreach (self::SETTINGS as $key) {
            if (array_key_exists($key, $after) && $this->settingValue($key, $before[$key] ?? null) !== $this->settingValue($key, $after[$key])) {
                $changed[] = $key;
            }
        }

        if ($changed !== []) {
            $this->record(self::SETTINGS_ENTITY, null, 'security_settings_changed', $actorId, JournalOrigin::User, ['settings' => $changed]);
        }
    }

    private function settingValue(string $key, mixed $value): string
    {
        if (in_array($key, self::BOOLEAN_SETTINGS, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return trim((string) $value);
    }

    private function printable(string $text): string
    {
        return htmlspecialchars(mb_substr($text, 0, self::NAME_LENGTH), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(string $entity, ?int $entityId, string $action, ?int $actorId, JournalOrigin $origin, array $details = []): void
    {
        $this->attempt(fn () => $this->audit->write($entity, $entityId, $action, $actorId, $origin, $details));
    }

    private function attempt(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $failure) {
            log_message('error', 'The audit log could not take a security entry: {message}', ['message' => $failure->getMessage()]);
        }
    }
}
