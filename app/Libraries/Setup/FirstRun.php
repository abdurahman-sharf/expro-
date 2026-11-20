<?php

namespace App\Libraries\Setup;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockOutcome;
use App\Libraries\Accounting\NamedLock;
use App\Libraries\Accounting\SalesTaxSettings;
use App\Libraries\Tax\CompanyTaxProfile;
use App\Models\Appconfig;
use App\Models\Stock_location;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeZone;
use Throwable;

/**
 * The company set up of a new installation, step by step. A step writes by primary key into the rows the schema
 * script already planted (the company, the main branch, the first stock location) and never adds a second one, so
 * saving a step again, after a dropped connection or from another tab, changes nothing but what was edited. The
 * installation counts as set up only after every step was read back from the database and agrees with the others.
 */
final class FirstRun
{
    public const PROGRESS_KEY = 'install_setup_progress';
    public const LANGUAGE     = 'language';
    public const COMPANY      = 'company';
    public const BRANCH       = 'branch';
    public const TAX          = 'tax';
    public const ACCOUNTING   = 'accounting';
    public const STEPS        = [self::LANGUAGE, self::COMPANY, self::BRANCH, self::TAX, self::ACCOUNTING];
    public const FINISH       = 'finish';
    public const NAME_MAX     = 100;
    public const TEXT_MAX     = 255;
    public const SHORT_MAX    = 64;
    public const COMPANY_ID   = 1;

    private BaseConnection $db;
    private Appconfig $config;
    private InstallState $state;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?InstallState $state = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = model(Appconfig::class);
        $this->state  = $state ?? new InstallState($this->db);
        $this->audit  = new AuditLog($this->db);
    }

    /**
     * @return array<string, bool>
     */
    public function progress(): array
    {
        $saved = json_decode($this->config->get_value(self::PROGRESS_KEY, '{}'), true);
        $saved = is_array($saved) ? $saved : [];
        $done  = [];

        foreach ([self::LANGUAGE, self::COMPANY, self::BRANCH, self::TAX] as $step) {
            $done[$step] = ($saved[$step] ?? false) === true;
        }

        $done[self::ACCOUNTING] = $this->accountingIsReady();

        return $done;
    }

    public function currentStep(): string
    {
        foreach ($this->progress() as $step => $done) {
            if (! $done) {
                return $step;
            }
        }

        return self::FINISH;
    }

    /**
     * @return array<string, string>
     */
    public function values(): array
    {
        $tax = new SalesTaxSettings($this->db);

        return [
            'language'          => $this->config->get_value('language_code') . ':' . $this->config->get_value('language'),
            'country'           => strtoupper($this->config->get_value('country_codes')),
            'timezone'          => $this->config->get_value('timezone'),
            'currency_code'     => strtoupper($this->config->get_value('currency_code')),
            'currency_symbol'   => html_entity_decode($this->config->get_value('currency_symbol')),
            'currency_decimals' => $this->config->get_value('currency_decimals', '2'),
            'company'           => $this->config->get_value('company'),
            'address'           => $this->config->get_value('address'),
            'phone'             => $this->config->get_value('phone'),
            'email'             => $this->config->get_value('email'),
            'tax_id'            => $this->config->get_value('tax_id'),
            'branch_name'       => (string) ($this->defaultBranch()->name ?? ''),
            'location_name'     => (string) ($this->mainLocation()->location_name ?? ''),
            'tax_included'      => $tax->defaultIncluded() ? '1' : '0',
            'tax_name'          => $this->config->get_value('default_tax_1_name'),
            'tax_rate'          => $this->config->get_value('default_tax_1_rate'),
        ];
    }

    /**
     * @param array<string, string> $input
     *
     * @throws FirstRunException
     */
    public function saveLanguage(array $input, int $employeeId): void
    {
        $language = $input['language'] ?? '';
        $country  = strtoupper(trim($input['country'] ?? ''));
        $code     = strtoupper(trim($input['currency_code'] ?? ''));
        $symbol   = trim($input['currency_symbol'] ?? '');
        $timezone = trim($input['timezone'] ?? '');
        $region   = $country === '' ? null : Regions::find($country);

        helper('locale');

        if (! array_key_exists($language, get_languages())) {
            throw new FirstRunException('language_invalid');
        }

        if ($country !== '' && $region === null) {
            throw new FirstRunException('country_invalid');
        }

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new FirstRunException('currency_invalid');
        }

        $symbol = $symbol === '' ? $code : $symbol;

        if (mb_strlen($symbol) > 10) {
            throw new FirstRunException('symbol_invalid');
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new FirstRunException('timezone_invalid');
        }

        $decimals = filter_var($input['currency_decimals'] ?? ($region['decimals'] ?? 2), FILTER_VALIDATE_INT);

        if ($decimals === false || $decimals < 0 || $decimals > 4) {
            throw new FirstRunException('decimals_invalid');
        }

        $this->locked(function () use ($language, $country, $region, $code, $symbol, $timezone, $decimals, $employeeId): void {
            $books = (new AccountingSettings($this->db))->baseCurrency();

            if ($books !== '' && $books !== $code) {
                throw new FirstRunException('currency_locked', ['currency' => $books]);
            }

            [$languageCode, $languageName] = explode(':', $language);

            $changes = [
                'language_code'     => $languageCode,
                'language'          => $languageName,
                'currency_code'     => $code,
                'currency_symbol'   => htmlspecialchars($symbol),
                'currency_decimals' => (string) $decimals,
                'timezone'          => $timezone,
                'country_codes'     => strtolower($country),
            ];

            if ($region !== null) {
                $changes['dateformat'] = in_array($country, Regions::US_DATE_COUNTRIES, true) ? 'm/d/Y' : 'd/m/Y';
                $changes['timeformat'] = 'H:i:s';
            }

            $this->saveSettings($changes);
            $this->markDone(self::LANGUAGE, $employeeId);
        });
    }

    /**
     * @param array<string, string> $input
     *
     * @throws FirstRunException
     */
    public function saveCompany(array $input, int $employeeId): void
    {
        $name    = trim($input['company'] ?? '');
        $address = trim($input['address'] ?? '');
        $phone   = trim($input['phone'] ?? '');
        $email   = mb_strtolower(trim($input['email'] ?? ''));
        $taxId   = trim($input['tax_id'] ?? '');

        if ($name === '' || mb_strlen($name) > self::TEXT_MAX) {
            throw new FirstRunException('company_required', ['max' => self::TEXT_MAX]);
        }

        if (mb_strlen($address) > self::TEXT_MAX) {
            throw new FirstRunException('address_too_long', ['max' => self::TEXT_MAX]);
        }

        if (mb_strlen($phone) > self::SHORT_MAX || preg_match('/^[0-9+()\-. \/]*$/', $phone) !== 1) {
            throw new FirstRunException('phone_invalid');
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new FirstRunException('email_invalid');
        }

        if (mb_strlen($taxId) > self::SHORT_MAX || preg_match('/^[A-Za-z0-9 .\-\/]*$/', $taxId) !== 1) {
            throw new FirstRunException('tax_id_invalid', ['max' => self::SHORT_MAX]);
        }

        $this->locked(function () use ($name, $address, $phone, $email, $taxId, $employeeId): void {
            $this->inTransaction(function () use ($name, $address, $phone, $email, $taxId, $employeeId): void {
                $this->saveSettings(['company' => $name, 'address' => $address, 'phone' => $phone, 'email' => $email]);
                $this->writeCompanyRow($name);
                (new CompanyTaxProfile($this->db))->updateVatIdentity(null, $taxId, '', $employeeId);

                $branch = $this->defaultBranch();

                $this->db->table('branches')->where('branch_id', (int) $branch->branch_id)->update([
                    'address'          => $address === '' ? null : $address,
                    'phone'            => $phone === '' ? null : $phone,
                    'tax_registration' => $taxId === '' ? null : $taxId,
                ]);
            });

            $this->markDone(self::COMPANY, $employeeId);
        });
    }

    /**
     * @param array<string, string> $input
     *
     * @throws FirstRunException
     */
    public function saveBranch(array $input, int $employeeId): void
    {
        $branchName   = trim($input['branch_name'] ?? '');
        $locationName = trim($input['location_name'] ?? '');

        if ($branchName === '' || mb_strlen($branchName) > self::NAME_MAX) {
            throw new FirstRunException('branch_required', ['max' => self::NAME_MAX]);
        }

        if ($locationName === '' || mb_strlen($locationName) > self::NAME_MAX || preg_match('/[\x00-\x1F\x7F]/', $locationName) === 1) {
            throw new FirstRunException('location_required', ['max' => self::NAME_MAX]);
        }

        $this->locked(function () use ($branchName, $locationName, $employeeId): void {
            $location   = $this->mainLocation();
            $locationId = $location === null ? NEW_ENTRY : (int) $location->location_id;

            if ($this->db->table('stock_locations')->where('deleted', 0)->where('location_name', $locationName)->where('location_id !=', $locationId)->countAllResults() > 0) {
                throw new FirstRunException('location_taken');
            }

            $this->inTransaction(function () use ($branchName, $locationName, $locationId): void {
                $branch = $this->defaultBranch();

                $this->db->table('branches')->where('branch_id', (int) $branch->branch_id)->update(['name' => $branchName]);
                $this->writeMainLocation($locationName, $locationId, (int) $branch->branch_id);
            });

            $this->markDone(self::BRANCH, $employeeId);
        });
    }

    /**
     * @param array<string, string> $input
     *
     * @throws FirstRunException
     */
    public function saveTax(array $input, int $employeeId): void
    {
        helper('locale');

        $name     = trim($input['tax_name'] ?? '');
        $rawRate  = trim((string) ($input['tax_rate'] ?? ''));
        $included = ($input['tax_included'] ?? '') === '1';
        $rate     = '';

        if ($rawRate !== '') {
            $rate = parse_tax((string) filter_var($rawRate, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION));

            if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
                throw new FirstRunException('tax_rate_invalid');
            }
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw new FirstRunException('tax_name_invalid', ['max' => self::NAME_MAX]);
        }

        if ($rate !== '' && (float) $rate > 0 && $name === '') {
            throw new FirstRunException('tax_name_required');
        }

        $this->locked(function () use ($name, $rate, $included, $employeeId): void {
            $this->inTransaction(function () use ($name, $rate, $included, $employeeId): void {
                $this->saveSettings(['default_tax_1_name' => $name, 'default_tax_1_rate' => (string) $rate]);

                (new SalesTaxSettings($this->db))->update([SalesTaxSettings::INCLUDED_KEY => $included], $employeeId);
            });

            $this->markDone(self::TAX, $employeeId);
        });
    }

    /**
     * What still stands in the way of calling the installation set up. Each entry is the end of a line of the
     * Setup language file.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $progress = $this->progress();

        foreach ([self::LANGUAGE, self::COMPANY, self::BRANCH, self::TAX] as $step) {
            if (! $progress[$step]) {
                $problems[] = $step . '_not_saved';
            }
        }

        $company = $this->db->table('companies')->where('company_id', self::COMPANY_ID)->get()->getRow();

        if ($company === null || trim((string) $company->name) === '') {
            $problems[] = 'company_row_missing';
        }

        if ($this->db->table('branches')->where('is_default', 1)->where('active', 1)->countAllResults() !== 1) {
            $problems[] = 'branch_default_not_single';
        } elseif ($this->mainLocation() === null) {
            $problems[] = 'location_missing';
        }

        $settings = new AccountingSettings($this->db);
        $currency = strtoupper(trim($this->config->get_value('currency_code')));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ($settings->isEnabled() && $settings->baseCurrency() !== $currency)) {
            $problems[] = 'currency_mismatch';
        }

        $rate = $this->config->get_value('default_tax_1_rate');

        if ($rate !== '' && (! is_numeric($rate) || (float) $rate < 0)) {
            $problems[] = 'tax_invalid';
        }

        if (! $progress[self::ACCOUNTING]) {
            $problems[] = 'accounting_not_ready';
        } elseif (array_sum((new EntityAccountService())->missingCounts()) > 0) {
            $problems[] = 'accounting_entities_missing';
        }

        return $problems;
    }

    /**
     * @throws FirstRunException
     */
    public function finish(int $employeeId): void
    {
        $this->locked(function () use ($employeeId): void {
            $problems = $this->problems();

            if ($problems !== []) {
                throw new FirstRunException('incomplete', ['problems' => $problems]);
            }

            if (! $this->state->markSetUp()) {
                throw new FirstRunException('closed');
            }

            $this->config->delete(self::PROGRESS_KEY);
            $this->state->forgetCode();
            $this->audit->write('settings', null, 'setup_completed', $employeeId, JournalOrigin::User);
        });
    }

    private function accountingIsReady(): bool
    {
        $settings = new AccountingSettings($this->db);

        return $settings->isEnabled() && $settings->readiness() === [];
    }

    private function defaultBranch(): object
    {
        $branch = $this->db->table('branches')->where('is_default', 1)->orderBy('branch_id')->get(1)->getRow();

        if ($branch !== null) {
            return $branch;
        }

        $this->writeCompanyRow((string) ($this->config->get_value('company') ?: 'Company'), '');
        $this->db->table('branches')->ignore(true)->insert(['branch_id' => 1, 'company_id' => self::COMPANY_ID, 'code' => 'HQ', 'name' => 'HQ', 'is_default' => 1]);
        $this->db->table('branches')->where('branch_id', 1)->update(['is_default' => 1, 'active' => 1]);
        $this->db->query('INSERT IGNORE INTO ' . $this->db->prefixTable('employee_branches') . ' (person_id, branch_id, is_default) VALUES (?, 1, 1)', [InstallState::OWNER_ID]);

        return $this->db->table('branches')->where('branch_id', 1)->get()->getRow();
    }

    private function mainLocation(): ?object
    {
        $branchId = (int) ($this->db->table('branches')->select('branch_id')->where('is_default', 1)->orderBy('branch_id')->get(1)->getRow()->branch_id ?? 1);

        return $this->db->table('stock_locations')->where('deleted', 0)->where('branch_id', $branchId)->orderBy('is_primary', 'DESC')->orderBy('location_id')->get(1)->getRow();
    }

    /**
     * The name of the company. Its VAT number is written by the tax profile, the one place that writes it.
     */
    private function writeCompanyRow(string $name): void
    {
        $exists = $this->db->table('companies')->where('company_id', self::COMPANY_ID)->countAllResults() > 0;

        if ($exists) {
            $this->db->table('companies')->where('company_id', self::COMPANY_ID)->update(['name' => $name]);

            return;
        }

        $this->db->table('companies')->insert(['company_id' => self::COMPANY_ID, 'name' => $name]);
    }

    /**
     * Gives the main stock location its name, or makes the first one when the branch has none. The other active
     * locations keep their names and the quantities they hold.
     *
     * @throws FirstRunException
     */
    private function writeMainLocation(string $name, int $locationId, int $branchId): void
    {
        $data = ['location_name' => $name];

        if (! model(Stock_location::class)->save_value($data, $locationId)) {
            throw new FirstRunException('location_failed');
        }

        $savedId = $locationId === NEW_ENTRY ? (int) $data['location_id'] : $locationId;

        $this->db->table('stock_locations')->where('location_id', $savedId)->update(['branch_id' => $branchId]);
    }

    /**
     * @param array<string, string> $changes
     *
     * @throws FirstRunException
     */
    private function saveSettings(array $changes): void
    {
        if (! $this->config->batch_save($changes)) {
            throw new FirstRunException('save_failed');
        }
    }

    private function markDone(string $step, int $employeeId): void
    {
        $saved        = json_decode($this->config->get_value(self::PROGRESS_KEY, '{}'), true);
        $saved        = is_array($saved) ? $saved : [];
        $saved[$step] = true;

        $this->saveSettings([self::PROGRESS_KEY => json_encode($saved)]);
        $this->audit->write('settings', null, 'setup_step_saved', $employeeId, JournalOrigin::User, ['step' => $step]);
    }

    /**
     * @param callable(): void $work
     *
     * @throws FirstRunException
     */
    private function inTransaction(callable $work): void
    {
        $this->db->transBegin();

        try {
            $work();
            $this->db->transCommit();
        } catch (Throwable $failure) {
            $this->db->transRollback();

            throw $failure;
        }
    }

    /**
     * @param callable(): void $work
     *
     * @throws FirstRunException
     */
    private function locked(callable $work): void
    {
        if (! $this->state->needsSetup()) {
            throw new FirstRunException('closed');
        }

        $lock  = new NamedLock($this->db, 0);
        $name  = 'ospos_first_run_' . substr(sha1($this->db->getDatabase()), 0, 10);
        $taken = $lock->take($name);

        if ($taken !== LockOutcome::Taken) {
            throw new FirstRunException('busy');
        }

        try {
            $work();
        } finally {
            $lock->release($name);
        }
    }
}
