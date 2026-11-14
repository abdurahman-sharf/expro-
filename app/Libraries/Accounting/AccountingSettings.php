<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

final class AccountingSettings
{
    private const ENABLED_KEY          = 'accounting_enabled';
    private const BASE_CURRENCY_KEY    = 'accounting_base_currency';
    private const START_DATE_KEY       = 'accounting_start_date';
    private const AUTO_YEAR_KEY        = 'accounting_auto_fiscal_year';
    private const LINE_TEXT_KEY        = 'accounting_line_description_required';
    private const EXPENSE_ACCOUNT_KEY  = 'accounting_expense_account_required';
    private const REVENUE_ACCOUNT_KEY  = 'accounting_revenue_account_required';
    private const CUSTOMER_OPENING_KEY = 'accounting_customer_opening';
    private const BACKFILL_KEY         = 'accounting_backfill_enabled';
    private const SALES_ADJUSTMENT_KEY = 'accounting_sales_adjustment';
    private const SALES_SHIPPING_KEY   = 'accounting_sales_shipping';
    private const SHIPPING_TAX_KEY     = 'accounting_shipping_tax_percent';
    private const DEBIT_NOTE_KEY       = 'accounting_debit_note';
    private const SALES_TEMPLATE_KEY   = 'accounting_journal_description_sales';
    private const BUYING_TEMPLATE_KEY  = 'accounting_journal_description_purchases';
    private const PICKER_KEY           = 'accounting_picker_threshold';
    public const DEFAULT_PICKER_SIZE   = 200;
    public const MAX_PICKER_SIZE       = 100000;

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

    public function baseCurrency(): string
    {
        $code = $this->config->get_value(self::BASE_CURRENCY_KEY);

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : '';
    }

    public function isEnabled(): bool
    {
        return $this->config->get_value(self::ENABLED_KEY, '0') === '1';
    }

    public function startDate(): ?string
    {
        $value  = $this->config->get_value(self::START_DATE_KEY);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    public function autoFiscalYear(): bool
    {
        return $this->config->get_value(self::AUTO_YEAR_KEY, '1') === '1';
    }

    public function lineDescriptionRequired(): bool
    {
        return $this->config->get_value(self::LINE_TEXT_KEY, '0') === '1';
    }

    /**
     * Whether an expense must be charged to a category that has an account of its own, instead of
     * falling back to the account of expenses the routing names.
     */
    public function expenseAccountRequired(): bool
    {
        return $this->config->get_value(self::EXPENSE_ACCOUNT_KEY, '0') === '1';
    }

    /**
     * Whether a customer may be given an opening balance. It is off until it is turned on, as the
     * reference system keeps it, so that nothing of it shows or works on a database where nobody asked.
     */
    public function customerOpeningEnabled(): bool
    {
        return $this->config->get_value(self::CUSTOMER_OPENING_KEY, '0') === '1';
    }

    /**
     * Whether a batch of the retroactive posting may be approved. It is off until it is turned on, so that
     * nothing of the past enters the books on a database where nobody ordered it; a batch is previewed
     * either way, because a preview changes no balance.
     */
    public function backfillEnabled(): bool
    {
        return $this->config->get_value(self::BACKFILL_KEY, '0') === '1';
    }

    /**
     * Whether an invoice, a quote or a credit note may carry an adjustment (a label and a signed amount).
     * It is off until it is turned on, as nothing of it exists where nobody asked for it.
     */
    public function salesAdjustmentEnabled(): bool
    {
        return $this->config->get_value(self::SALES_ADJUSTMENT_KEY, '0') === '1';
    }

    /**
     * Whether an invoice, a quote or a credit note may carry a shipping charge. It is off until it is turned on.
     */
    public function salesShippingEnabled(): bool
    {
        return $this->config->get_value(self::SALES_SHIPPING_KEY, '0') === '1';
    }

    /**
     * The tax rate that applies to a shipping charge when the document asks for it, in percent. Zero means
     * the shipping carries no tax.
     *
     * @return numeric-string
     */
    public function shippingTaxPercent(): string
    {
        $value = $this->config->get_value(self::SHIPPING_TAX_KEY, '0');

        return preg_match('/^\d{1,3}(\.\d{1,4})?$/', $value) === 1 && bccomp($value, '100', 4) <= 0 ? $value : '0';
    }

    /**
     * How many accounts, customers or suppliers a list may hold before the screens that would list them all
     * offer a search box instead.
     */
    public function pickerThreshold(): int
    {
        $value = $this->config->get_value(self::PICKER_KEY, (string) self::DEFAULT_PICKER_SIZE);

        return preg_match('/^\d{1,6}$/', $value) === 1 && (int) $value >= 1 && (int) $value <= self::MAX_PICKER_SIZE ? (int) $value : self::DEFAULT_PICKER_SIZE;
    }

    /**
     * Whether the sales debit note is available: a document that raises what a customer owes without an
     * invoice. It is off until it is turned on, as the reference system keeps it.
     */
    public function debitNoteEnabled(): bool
    {
        return $this->config->get_value(self::DEBIT_NOTE_KEY, '0') === '1';
    }

    /**
     * The template the automatic journals of sales or of purchases are described with, or an empty text
     * for the descriptions the application writes.
     */
    public function journalDescriptionTemplate(string $scope): string
    {
        $value = $this->config->get_value($scope === JournalDescriptionTemplate::PURCHASES ? self::BUYING_TEMPLATE_KEY : self::SALES_TEMPLATE_KEY, '');

        return JournalDescriptionTemplate::isValid($value) ? trim($value) : '';
    }

    /**
     * Whether a receipt voucher must be credited to an account chosen on it or to a category that
     * has one, instead of falling back to the account of other revenue the routing names.
     */
    public function revenueAccountRequired(): bool
    {
        return $this->config->get_value(self::REVENUE_ACCOUNT_KEY, '0') === '1';
    }

    /**
     * The currency and the start date are fixed once a journal has been posted.
     */
    public function isLocked(): bool
    {
        return $this->db->table('accounting_journals')
            ->where('company_id', CompanyContext::id())
            ->whereIn('status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->countAllResults() > 0;
    }

    public function lastManualNumber(): int
    {
        $row = $this->db->table('accounting_sequences')->where('company_id', CompanyContext::id())->where('series', 'manual')->get()->getRow();

        return $row === null ? 0 : (int) $row->last_number;
    }

    /**
     * @param array{base_currency?: string, start_date?: string, auto_fiscal_year?: bool, line_description_required?: bool, expense_account_required?: bool, revenue_account_required?: bool, customer_opening?: bool, backfill_enabled?: bool, sales_adjustment?: bool, sales_shipping?: bool, shipping_tax_percent?: string, debit_note?: bool, journal_description_sales?: string, journal_description_purchases?: string, picker_threshold?: string} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(array $input, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $before  = ['base_currency' => $this->baseCurrency(), 'start_date' => $this->startDate(), 'auto_fiscal_year' => $this->autoFiscalYear(), 'line_description_required' => $this->lineDescriptionRequired(), 'expense_account_required' => $this->expenseAccountRequired(), 'revenue_account_required' => $this->revenueAccountRequired(), 'customer_opening' => $this->customerOpeningEnabled(), 'backfill_enabled' => $this->backfillEnabled(), 'sales_adjustment' => $this->salesAdjustmentEnabled(), 'sales_shipping' => $this->salesShippingEnabled(), 'shipping_tax_percent' => $this->shippingTaxPercent(), 'debit_note' => $this->debitNoteEnabled(), 'journal_description_sales' => $this->journalDescriptionTemplate(JournalDescriptionTemplate::SALES), 'journal_description_purchases' => $this->journalDescriptionTemplate(JournalDescriptionTemplate::PURCHASES), 'picker_threshold' => (string) $this->pickerThreshold()];
        $after   = $before;
        $changes = [];

        if (array_key_exists('base_currency', $input)) {
            $currency = strtoupper(trim((string) $input['base_currency']));

            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new AccountingException(AccountingError::InvalidCurrency, 'The currency must be a three-letter code.');
            }

            $after['base_currency'] = $currency;
        }

        if (array_key_exists('start_date', $input)) {
            $date   = trim((string) $input['start_date']);
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new AccountingException(AccountingError::InvalidDate, 'The start date must be a valid Y-m-d date.');
            }

            $after['start_date'] = $date;
        }

        if (array_key_exists('auto_fiscal_year', $input)) {
            $after['auto_fiscal_year'] = (bool) $input['auto_fiscal_year'];
        }

        if (array_key_exists('line_description_required', $input)) {
            $after['line_description_required'] = (bool) $input['line_description_required'];
        }

        if (array_key_exists('expense_account_required', $input)) {
            $after['expense_account_required'] = (bool) $input['expense_account_required'];
        }

        if (array_key_exists('revenue_account_required', $input)) {
            $after['revenue_account_required'] = (bool) $input['revenue_account_required'];
        }

        if (array_key_exists('customer_opening', $input)) {
            $after['customer_opening'] = (bool) $input['customer_opening'];
        }

        if (array_key_exists('backfill_enabled', $input)) {
            $after['backfill_enabled'] = (bool) $input['backfill_enabled'];
        }

        foreach (['sales_adjustment', 'sales_shipping', 'debit_note'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $after[$flag] = (bool) $input[$flag];
            }
        }

        if (array_key_exists('shipping_tax_percent', $input)) {
            $percent = trim((string) $input['shipping_tax_percent']);

            if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $percent) !== 1 || bccomp($percent, '100', 4) > 0) {
                throw new AccountingException(AccountingError::ShippingTaxPercentInvalid, 'The tax on shipping must be a percentage from 0 to 100.');
            }

            $after['shipping_tax_percent'] = $percent;
        }

        if (array_key_exists('picker_threshold', $input)) {
            $size = trim((string) $input['picker_threshold']);

            if (preg_match('/^\d{1,6}$/', $size) !== 1 || (int) $size < 1 || (int) $size > self::MAX_PICKER_SIZE) {
                throw new AccountingException(AccountingError::PickerThresholdInvalid, 'The number of entries before a search box is offered is a whole number from 1 to the maximum.', ['max' => self::MAX_PICKER_SIZE]);
            }

            $after['picker_threshold'] = (string) (int) $size;
        }

        foreach (['journal_description_sales', 'journal_description_purchases'] as $templateKey) {
            if (array_key_exists($templateKey, $input)) {
                $template = trim((string) $input[$templateKey]);

                if (! JournalDescriptionTemplate::isValid($template)) {
                    throw new AccountingException(AccountingError::JournalTemplateInvalid, 'The description template may only hold the placeholders {number}, {party}, {type} and {date}, no markup, and up to 200 characters.', ['max' => JournalDescriptionTemplate::MAX]);
                }

                $after[$templateKey] = $template;
            }
        }

        foreach ($after as $key => $value) {
            if ($value !== $before[$key]) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        if ((isset($changes['base_currency']) || isset($changes['start_date'])) && $this->isLocked()) {
            throw new AccountingException(AccountingError::SettingsLocked, 'The currency and the start date cannot change once a journal is posted.');
        }

        if (isset($changes['start_date']) && $this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id())->countAllResults() > 0 && ! $this->fiscalYearCovers($changes['start_date'])) {
            throw new AccountingException(AccountingError::StartDateOutsideFiscalYear, 'The start date must fall in a fiscal year.', ['date' => $changes['start_date']]);
        }

        $values = [];

        foreach ($changes as $key => $value) {
            $values[match ($key) {
                'base_currency'             => self::BASE_CURRENCY_KEY,
                'start_date'                => self::START_DATE_KEY,
                'auto_fiscal_year'          => self::AUTO_YEAR_KEY,
                'line_description_required' => self::LINE_TEXT_KEY,
                'expense_account_required'  => self::EXPENSE_ACCOUNT_KEY,
                'revenue_account_required'  => self::REVENUE_ACCOUNT_KEY,
                'customer_opening'          => self::CUSTOMER_OPENING_KEY,
                'backfill_enabled'          => self::BACKFILL_KEY,
                'sales_adjustment'          => self::SALES_ADJUSTMENT_KEY,
                'sales_shipping'            => self::SALES_SHIPPING_KEY,
                'shipping_tax_percent'      => self::SHIPPING_TAX_KEY,
                'debit_note'                => self::DEBIT_NOTE_KEY,
                'journal_description_sales'     => self::SALES_TEMPLATE_KEY,
                'journal_description_purchases' => self::BUYING_TEMPLATE_KEY,
                'picker_threshold'          => self::PICKER_KEY,
            }] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        $this->config->batch_save($values);
        $this->audit->write('settings', null, 'settings_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);
    }

    /**
     * @return list<ReadinessIssue>
     */
    public function readiness(): array
    {
        $issues = [];

        if ($this->db->table('accounting_accounts')->countAllResults() === 0) {
            $issues[] = new ReadinessIssue(ReadinessCode::NoAccounts);
        }

        if ($this->baseCurrency() === '') {
            $issues[] = new ReadinessIssue(ReadinessCode::BaseCurrencyMissing);
        }

        $startDate = $this->startDate();

        if ($startDate === null) {
            $issues[] = new ReadinessIssue(ReadinessCode::StartDateMissing);
        }

        if ($this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id())->countAllResults() === 0) {
            $issues[] = new ReadinessIssue(ReadinessCode::NoFiscalYear);
        } elseif ($startDate !== null && ! $this->fiscalYearCovers($startDate)) {
            $issues[] = new ReadinessIssue(ReadinessCode::StartDateOutsideFiscalYear, $startDate);
        }

        return [...$issues, ...$this->routingIssues(), ...(new RoutingService($this->db))->blockingIssues(), ...$this->entityAccountIssues()];
    }

    /**
     * @return list<ReadinessIssue> What is worth knowing before turning accounting on, none of it a reason to wait.
     */
    public function notices(): array
    {
        return [...(new RoutingService($this->db))->notices($this->startDate()), ...$this->unpostedNotices()];
    }

    /**
     * @throws AccountingNotReadyException
     */
    public function enable(?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $issues = $this->readiness();

        if ($issues !== []) {
            throw new AccountingNotReadyException($issues);
        }

        $this->config->save([self::ENABLED_KEY => '1']);
        $this->audit->write('settings', null, 'accounting_enabled', $employeeId, JournalOrigin::User);
    }

    public function disable(?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $this->config->save([self::ENABLED_KEY => '0']);
        $this->audit->write('settings', null, 'accounting_disabled', $employeeId, JournalOrigin::User);
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function requireManage(?int $employeeId): void
    {
        $this->guard ??= new AccountingGuard();
        $this->branches ??= new BranchGuard($this->db);

        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);
    }

    /**
     * @return list<ReadinessIssue> The expenses and the cash-ups the books do not hold, which only the retroactive posting adds.
     */
    private function unpostedNotices(): array
    {
        $issues   = [];
        $expenses = (new ExpensePosting($this->db))->unpostedCount();
        $cashups  = (new CashupPosting($this->db))->unpostedCount();

        if ($expenses > 0) {
            $issues[] = new ReadinessIssue(ReadinessCode::ExpensesUnposted, (string) $expenses);
        }

        if ($cashups > 0) {
            $issues[] = new ReadinessIssue(ReadinessCode::CashupsUnposted, (string) $cashups);
        }

        return $issues;
    }

    private function fiscalYearCovers(string $date): bool
    {
        return $this->db->table('accounting_fiscal_years')
            ->where('company_id', CompanyContext::id())
            ->where('start_date <=', $date)
            ->where('end_date >=', $date)
            ->countAllResults() > 0;
    }

    /**
     * @return list<ReadinessIssue>
     */
    private function entityAccountIssues(): array
    {
        $missing = array_sum((new EntityAccountService($this->db))->missingCounts());

        return $missing > 0 ? [new ReadinessIssue(ReadinessCode::EntityAccountsMissing, (string) $missing)] : [];
    }

    /**
     * @return list<ReadinessIssue>
     */
    private function routingIssues(): array
    {
        $rows = $this->db->table('accounting_routing r')
            ->select('r.routing_key, r.routing_type, r.account_id, a.is_group, a.active')
            ->join('accounting_accounts a', 'a.account_id = r.account_id', 'left')
            ->get()
            ->getResult();

        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row->routing_key] = $row;
        }

        $issues = [];

        foreach (RoutingKey::cases() as $key) {
            $row  = $byKey[$key->value] ?? null;
            $type = $row === null ? null : RoutingType::tryFrom((string) $row->routing_type);

            $effective = $type ?? $key->defaultType();

            if (! $key->applies($effective)) {
                $issues[] = new ReadinessIssue(ReadinessCode::RoutingTypeUnsupported, $key->value);

                continue;
            }

            if ($effective === RoutingType::Cancelled) {
                continue;
            }

            if ($row === null || $row->account_id === null) {
                if ($key->accountRequired()) {
                    $issues[] = new ReadinessIssue(ReadinessCode::RoutingUnmapped, $key->value);
                }
            } elseif ($row->is_group === null || (int) $row->is_group !== (int) $key->isEntity() || (int) $row->active === 0) {
                $issues[] = new ReadinessIssue(ReadinessCode::RoutingAccountInvalid, $key->value);
            }
        }

        return $issues;
    }
}
