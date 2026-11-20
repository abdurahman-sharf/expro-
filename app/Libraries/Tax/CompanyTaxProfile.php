<?php

namespace App\Libraries\Tax;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\EInvoicing\EInvoiceRules;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Models\Appconfig;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The one place that writes the VAT identity of the company: whether it is registered for VAT, its VAT number,
 * and the Saudi mode. The number is kept in the profile and copied, in the same transaction, to the two places
 * the rest of the application reads it from (the setting tax_id and the row of the company), which nothing else
 * writes. While the Saudi mode is off nothing here asks more than the screens already ask, so a business that is
 * not registered for VAT, or that has not turned the mode on, works as it did. Once the mode is on, a change of
 * the number needs both the settings permission and the e-invoicing permission, and every change is audited.
 */
final class CompanyTaxProfile
{
    use RunsInTransaction;

    public const CONFIG_PERMISSION = 'config';
    public const NUMBER_MAX        = 64;
    public const REASON_MAX        = 500;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private ?BranchGuard $branches;
    private Appconfig $config;
    private AuditLog $audit;

    /**
     * @var Closure(): string
     */
    private Closure $clock;

    /**
     * @param (Closure(): string)|null $clock The present moment as Y-m-d H:i:s, for a test.
     */
    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null, ?Closure $clock = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches;
        $this->config   = model(Appconfig::class);
        $this->audit    = new AuditLog($this->db);
        $this->clock    = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    public function current(): TaxProfile
    {
        $companyId = CompanyContext::id();
        $row       = $this->hasTable() ? $this->db->table('company_tax_profile')->where('company_id', $companyId)->get()->getRow() : null;

        if ($row === null) {
            return new TaxProfile($companyId, VatRegistration::Unknown, trim($this->config->get_value('tax_id')), false);
        }

        return new TaxProfile(
            companyId: $companyId,
            registration: VatRegistration::fromStored($row->vat_registered),
            vatNumber: (string) ($row->vat_number ?? ''),
            saudiMode: (int) $row->ksa_mode === 1,
            effectiveAt: $row->ksa_mode_effective_at === null ? null : (string) $row->ksa_mode_effective_at,
            activatedAt: $row->ksa_mode_activated_at === null ? null : (string) $row->ksa_mode_activated_at,
            activatedBy: $row->ksa_mode_activated_by === null ? null : (int) $row->ksa_mode_activated_by,
        );
    }

    public function isSaudiMode(): bool
    {
        return $this->current()->inSaudiModeAt(($this->clock)());
    }

    /**
     * Whether the company has issued an electronic document, after which its number is not changed without a reason.
     */
    public function hasIssuedDocuments(?string $since = null): bool
    {
        if (! $this->db->tableExists($this->db->prefixTable('einvoice_documents'), false)) {
            return false;
        }

        $builder = $this->db->table('einvoice_documents')->where('company_id', CompanyContext::id());

        if ($since !== null) {
            $builder->where('created_at >=', $since);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Changes whether the company is registered for VAT and its VAT number. Nothing that did not change is
     * written or checked, so a screen that posts every field leaves an unchanged number alone.
     *
     * @param VatRegistration|null $registration Null leaves the registration as it is.
     *
     * @return array<string, array{before: mixed, after: mixed}> What changed.
     *
     * @throws AccountingException|TaxProfileException
     */
    public function updateVatIdentity(?VatRegistration $registration, string $vatNumber, string $reason = '', ?int $employeeId = null): array
    {
        $current  = $this->current();
        $number   = trim($vatNumber);
        $reason   = trim($reason);
        $register = $registration ?? $current->registration;
        $changes  = [];

        if ($number !== $current->vatNumber) {
            $changes['vat_number'] = ['before' => $current->vatNumber, 'after' => $number];
        }

        if ($register !== $current->registration) {
            $changes['vat_registered'] = ['before' => $current->registration->value, 'after' => $register->value];
        }

        if ($changes === []) {
            return [];
        }

        if (mb_strlen($number) > self::NUMBER_MAX) {
            throw new TaxProfileException(TaxProfileError::NumberTooLong, 'The VAT number is too long.', ['max' => self::NUMBER_MAX]);
        }

        if ($current->saudiMode) {
            $this->guard->require(self::CONFIG_PERMISSION, $employeeId);
            $this->guard->require(AccountingGuard::EINVOICING_MANAGE, $employeeId);

            if ($register !== VatRegistration::Registered) {
                throw new TaxProfileException(TaxProfileError::ModeNeedsRegistered, 'The Saudi mode needs a business registered for VAT.');
            }

            if ($number === '') {
                throw new TaxProfileException(TaxProfileError::ModeNeedsNumber, 'The Saudi mode needs a VAT number.');
            }
        }

        if (($current->saudiMode || $register === VatRegistration::Registered) && $number !== '' && ! EInvoiceRules::vatNumber($number)) {
            throw new TaxProfileException(TaxProfileError::NumberInvalid, 'A Saudi VAT number is fifteen digits that begin and end with 3.');
        }

        if (isset($changes['vat_number']) && $this->hasIssuedDocuments()) {
            $this->requireReason($reason);
        }

        $this->transactional(function () use ($current, $number, $register, $employeeId, $changes, $reason): void {
            $this->writeRow($current->companyId, ['vat_number' => $number === '' ? null : $number, 'vat_registered' => $register->stored()], $employeeId);

            if ($number !== $current->vatNumber) {
                $this->writeMirrors($current->companyId, $number);
            }

            $this->audit->write('tax_profile', $current->companyId, 'vat_identity_changed', $employeeId, JournalOrigin::User, ['changes' => $changes, 'reason' => $reason]);
        });

        return $changes;
    }

    /**
     * Turns the Saudi mode on from a moment that is not in the past. It is never turned on by anything else.
     *
     * @param string $effectiveAt Y-m-d or Y-m-d H:i:s; empty for now.
     *
     * @throws AccountingException|BranchException|TaxProfileException
     */
    public function enableSaudiMode(string $effectiveAt, ?int $employeeId = null): void
    {
        $this->requireBothPermissions($employeeId);

        $current = $this->current();
        $now     = ($this->clock)();

        if ($current->saudiMode) {
            throw new TaxProfileException(TaxProfileError::ModeAlreadyOn, 'The Saudi mode is already on.');
        }

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            throw new TaxProfileException(TaxProfileError::AccountingDisabled, 'The Saudi mode needs accounting to be on.');
        }

        if ($current->registration !== VatRegistration::Registered) {
            throw new TaxProfileException(TaxProfileError::ModeNeedsRegistered, 'The Saudi mode needs a business registered for VAT.');
        }

        if (! EInvoiceRules::vatNumber($current->vatNumber)) {
            throw new TaxProfileException(TaxProfileError::ModeNeedsNumber, 'The Saudi mode needs a valid VAT number.');
        }

        $effective = $this->moment($effectiveAt, $now);

        if (strcmp($effective, substr($now, 0, 16)) < 0) {
            throw new TaxProfileException(TaxProfileError::EffectiveDateInPast, 'The Saudi mode cannot take effect in the past.');
        }

        $this->transactional(function () use ($current, $effective, $now, $employeeId): void {
            $this->writeRow($current->companyId, ['ksa_mode' => 1, 'ksa_mode_effective_at' => $effective, 'ksa_mode_activated_at' => $now, 'ksa_mode_activated_by' => $employeeId], $employeeId);
            $this->audit->write('tax_profile', $current->companyId, 'saudi_mode_enabled', $employeeId, JournalOrigin::User, ['effective_at' => $effective]);
        });
    }

    /**
     * Turns the Saudi mode off. Before any document was issued under it, the two permissions that turn it on
     * turn it off; once one was, only the onboarding permission does, with a reason.
     *
     * @throws AccountingException|BranchException|TaxProfileException
     */
    public function disableSaudiMode(string $reason, ?int $employeeId = null): void
    {
        $current = $this->current();
        $reason  = trim($reason);

        if (! $current->saudiMode) {
            throw new TaxProfileException(TaxProfileError::ModeAlreadyOff, 'The Saudi mode is already off.');
        }

        $issued = $this->hasIssuedDocuments($current->activatedAt);

        if ($issued) {
            $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);
            $this->requireCompanyScope($employeeId);
            $this->requireReason($reason);
        } else {
            $this->requireBothPermissions($employeeId);

            if (mb_strlen($reason) > self::REASON_MAX) {
                throw new TaxProfileException(TaxProfileError::ReasonTooLong, 'The reason is too long.', ['max' => self::REASON_MAX]);
            }
        }

        $this->transactional(function () use ($current, $employeeId, $reason, $issued): void {
            $this->writeRow($current->companyId, ['ksa_mode' => 0, 'ksa_mode_effective_at' => null, 'ksa_mode_activated_at' => null, 'ksa_mode_activated_by' => null], $employeeId);
            $this->audit->write('tax_profile', $current->companyId, 'saudi_mode_disabled', $employeeId, JournalOrigin::User, ['reason' => $reason, 'documents_issued' => $issued, 'was_effective_at' => $current->effectiveAt]);
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function requireBothPermissions(?int $employeeId): void
    {
        $this->guard->require(self::CONFIG_PERMISSION, $employeeId);
        $this->guard->require(AccountingGuard::EINVOICING_MANAGE, $employeeId);
        $this->requireCompanyScope($employeeId);
    }

    /**
     * @throws BranchException
     */
    private function requireCompanyScope(?int $employeeId): void
    {
        $this->branches ??= new BranchGuard($this->db);
        $this->branches->requireCompanyScope($employeeId);
    }

    /**
     * @throws TaxProfileException
     */
    private function requireReason(string $reason): void
    {
        if ($reason === '') {
            throw new TaxProfileException(TaxProfileError::ReasonRequired, 'A reason is required once documents have been issued.');
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new TaxProfileException(TaxProfileError::ReasonTooLong, 'The reason is too long.', ['max' => self::REASON_MAX]);
        }
    }

    /**
     * @throws TaxProfileException
     */
    private function moment(string $value, string $now): string
    {
        $value = trim($value);

        if ($value === '') {
            return substr($now, 0, 16) . ':00';
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($parsed === false || $parsed->format($format) !== $value) {
                continue;
            }

            if ($format === 'Y-m-d' && $value === substr($now, 0, 10)) {
                return substr($now, 0, 16) . ':00';
            }

            return $parsed->format('Y-m-d H:i:00');
        }

        throw new TaxProfileException(TaxProfileError::EffectiveDateInvalid, 'The effective date is not a date.');
    }

    /**
     * @param array<string, mixed> $values
     *
     * @throws AccountingException
     */
    private function writeRow(int $companyId, array $values, ?int $employeeId): void
    {
        $values = [...$values, 'updated_by' => $employeeId, 'updated_at' => ($this->clock)()];
        $exists = $this->db->table('company_tax_profile')->where('company_id', $companyId)->countAllResults() > 0;
        $saved  = $exists
            ? $this->db->table('company_tax_profile')->where('company_id', $companyId)->update($values)
            : $this->db->table('company_tax_profile')->insert(['company_id' => $companyId, ...$values]);

        if ($saved === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The tax profile could not be saved.');
        }
    }

    /**
     * @throws AccountingException
     */
    private function writeMirrors(int $companyId, string $number): void
    {
        if (! $this->config->batch_save(['tax_id' => $number])) {
            throw new AccountingException(AccountingError::WriteFailed, 'The VAT number could not be saved.');
        }

        $this->db->table('companies')->where('company_id', $companyId)->update(['tax_id' => $number === '' ? null : $number]);
    }

    private function hasTable(): bool
    {
        return $this->db->tableExists($this->db->prefixTable('company_tax_profile'), false);
    }
}
