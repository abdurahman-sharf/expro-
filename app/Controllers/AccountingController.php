<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\MemoryExhaustedNotice;
use App\Libraries\Accounting\PostingException;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\Integrations\PaymentMethods;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\ResponseInterface;

abstract class AccountingController extends Secure_Controller
{
    protected const DEFAULT_PAGE_SIZE = 25;

    private ?BranchGuard $branchGuard = null;

    /**
     * The settings screen is the one screen that must open while accounting is off, because it
     * is where accounting is turned on.
     */
    public function __construct(string $moduleId, bool $requiresAccounting = true)
    {
        parent::__construct($moduleId);

        MemoryExhaustedNotice::arm();

        if ($requiresAccounting && ! (new AccountingSettings())->isEnabled()) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    protected function employeeId(): int
    {
        return (int) $this->global_view_data['user_info']->person_id;
    }

    protected function can(string $permissionId): bool
    {
        return $this->employee->has_grant($permissionId, $this->employeeId());
    }

    /**
     * A screen that is open only to who holds a permission of its own, on top of the grant of the module, answers
     * everyone else with the page that says access is refused, the way the sidebar already leaves it out.
     *
     * @throws RedirectException
     */
    protected function requireGrant(string $permissionId): void
    {
        if (! $this->can($permissionId)) {
            throw new RedirectException('no_access/' . $this->global_view_data['controller_name'] . '/' . $permissionId);
        }
    }

    protected function branchGuard(): BranchGuard
    {
        return $this->branchGuard ??= new BranchGuard(null, $this->employee);
    }

    /**
     * @return list<int>|null Null means every branch.
     */
    protected function branchIds(): ?array
    {
        return $this->branchGuard()->allowedBranchIds($this->employeeId());
    }

    protected function seesBranches(): bool
    {
        return $this->branchGuard()->seesBranches($this->employeeId());
    }

    /**
     * Changes to the chart and the cost centers concern the whole company, so they need every
     * branch as well as the permission.
     */
    protected function canManage(string $permissionId): bool
    {
        return $this->can($permissionId) && $this->branchGuard()->isUnrestricted($this->employeeId());
    }

    /**
     * Someone limited to some branches is told that totals leave the others out. Someone who
     * can only ever see one branch has nothing hidden from them to explain.
     */
    protected function scopeNoticeNeeded(): bool
    {
        return $this->branchIds() !== null && count($this->branchGuard()->selectableBranches(null)) > 1;
    }

    /**
     * @param callable(): array<string, mixed> $action Returns the JSON body of a successful call.
     */
    protected function attempt(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON($action());
        } catch (AccountingException|BranchException|PostingException $exception) {
            return $this->response->setJSON([
                'success' => false,
                'message' => $this->messageFor($exception),
                'id'      => NEW_ENTRY,
            ]);
        } catch (EInvoiceException $exception) {
            return $this->response->setJSON([
                'success' => false,
                'message' => $exception->describe(),
                'id'      => NEW_ENTRY,
            ]);
        }
    }

    /**
     * @return array{success: false, message: string, id: int}|null The answer that refuses a way of payment switched off, or null when it may be used.
     */
    protected function paymentMethodRefusal(string $paymentType, ?string $stored = null): ?array
    {
        $refusal = (new PaymentMethods())->refusal($paymentType, $stored);

        return $refusal === null ? null : ['success' => false, 'message' => $refusal, 'id' => NEW_ENTRY];
    }

    protected function messageFor(AccountingException|BranchException|PostingException $exception): string
    {
        return AccountingMessage::of($exception);
    }

    protected function formatAmount(?string $amount): string
    {
        return to_currency_no_money($amount ?? '0');
    }

    protected function formatDate(?string $date): string
    {
        $time = $date === null ? false : strtotime($date);

        return $time === false ? '' : to_date($time);
    }

    protected function formatDateTime(?string $dateTime): string
    {
        $time = $dateTime === null ? false : strtotime($dateTime);

        return $time === false ? '' : to_datetime($time);
    }

    /**
     * @param 'active'|'draft'|'inactive'|'posted'|'reversed'|'system' $state
     */
    protected function badge(string $state): string
    {
        $class = match ($state) {
            'active', 'posted' => 'text-bg-success',
            'draft'  => 'text-bg-warning',
            'system' => 'text-bg-primary',
            default  => 'text-bg-secondary',
        };

        return '<span class="badge ' . $class . '">' . esc(lang('Accounting.state_' . $state)) . '</span>';
    }

    /**
     * Manual and reversal journals share one numbering. Automatic journals are numbered by
     * their id, so they carry a prefix to keep the two apart on screen.
     */
    protected function displayNumber(?string $series, ?string $number): string
    {
        if ($number === null || $number === '') {
            return '';
        }

        return $series === 'auto' ? 'A-' . $number : $number;
    }

    /**
     * A translated name for a stored code, the code itself when no translation exists yet.
     */
    protected function label(string $group, string $code): string
    {
        $key  = "Accounting.{$group}_{$code}";
        $line = lang($key);

        return $line === $key ? $code : $line;
    }

    protected function requestText(string $name): string
    {
        $value = $this->request->getGet($name);

        return is_string($value) ? trim($value) : '';
    }

    protected function amountCell(string $text): string
    {
        return '<bdi dir="ltr">' . esc($text) . '</bdi>';
    }
}
