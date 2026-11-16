<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The way to stop work while something is fixed without turning accounting off. While it holds, no new
 * sale, purchase, expense, cash-up, payment or voucher is accepted, and everything that is accepted is posted as
 * ever, so nothing ends up outside the books. Taking a document back, reading and the reports stay open.
 */
final class OperationsHold
{
    public const KEY        = 'accounting_operations_hold';
    public const REASON_MAX = 200;

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;
    private ?AccountingGuard $guard;
    private ?BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->config   = model(Appconfig::class);
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard;
        $this->branches = $branches;
    }

    /**
     * @return array{since: string, by: int, reason: string}|null
     */
    public function state(): ?array
    {
        $json = $this->config->get_value(self::KEY);

        if ($json === '') {
            return null;
        }

        $state = json_decode($json, true);

        return is_array($state) && isset($state['since'], $state['by'], $state['reason']) ? ['since' => (string) $state['since'], 'by' => (int) $state['by'], 'reason' => (string) $state['reason']] : null;
    }

    public function isOn(): bool
    {
        return $this->state() !== null;
    }

    /**
     * @throws PostingException
     */
    public function assertOpen(): void
    {
        $state = $this->state();

        if ($state !== null) {
            throw new PostingException(PostingError::OperationsHeld, 'New documents are not accepted while operations are on hold.', ['name' => $state['reason'], 'date' => $state['since']]);
        }
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function engage(string $reason, int $employeeId): void
    {
        $this->requireManage($employeeId);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::HoldReasonRequired, 'A reason of up to 200 characters is needed.', ['max' => self::REASON_MAX]);
        }

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            throw new AccountingException(AccountingError::HoldNeedsAccounting, 'Operations can be held only while accounting is on.');
        }

        if ($this->isOn()) {
            throw new AccountingException(AccountingError::HoldActive, 'Operations are on hold already.');
        }

        $since = date('Y-m-d H:i:s');

        $this->config->save([self::KEY => json_encode(['since' => $since, 'by' => $employeeId, 'reason' => $reason], JSON_UNESCAPED_UNICODE)]);
        $this->audit->write('settings', null, 'operations_held', $employeeId, JournalOrigin::User, ['reason' => $reason]);
    }

    /**
     * Operations are let back in only when the books have nothing left that stops a posting.
     *
     * @throws AccountingException|AccountingNotReadyException|BranchException
     */
    public function release(int $employeeId): void
    {
        $this->requireManage($employeeId);

        $state = $this->state();

        if ($state === null) {
            throw new AccountingException(AccountingError::HoldInactive, 'Operations are not on hold.');
        }

        $issues = (new AccountingSettings($this->db))->readiness();

        if ($issues !== []) {
            throw new AccountingNotReadyException($issues);
        }

        $minutes = (int) round((time() - (new DateTimeImmutable($state['since']))->getTimestamp()) / 60);

        $this->config->save([self::KEY => '']);
        $this->audit->write('settings', null, 'operations_released', $employeeId, JournalOrigin::User, ['reason' => $state['reason'], 'minutes' => $minutes]);
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function requireManage(int $employeeId): void
    {
        $this->guard ??= new AccountingGuard();
        $this->branches ??= new BranchGuard($this->db);

        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);
    }
}
