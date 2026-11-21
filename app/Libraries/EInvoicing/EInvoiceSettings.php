<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Tax\CompanyTaxProfile;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The settings of the document layer of electronic invoicing, off until someone with the permission turns
 * it on and only while accounting is on, because the numbers of a document are the books' numbers. The
 * seller is the company of the shop (its name and its tax number are the ones the shop already keeps) with
 * the identification and the national address the standard asks of it, and the way a line that carries no
 * tax is classified. The private key and the certificate are named by a path outside the application,
 * never held here, and a change is audited with what it replaced.
 */
final class EInvoiceSettings
{
    public const ENABLED   = 'einvoice_enabled';
    public const PHASE_TWO = 'einvoice_phase_two';
    public const FIELDS    = [
        'seller_identification_type'   => 'einvoice_seller_identification_type',
        'seller_identification_number' => 'einvoice_seller_identification_number',
        'seller_street'                => 'einvoice_seller_street',
        'seller_additional_street'     => 'einvoice_seller_additional_street',
        'seller_building_number'       => 'einvoice_seller_building_number',
        'seller_additional_number'     => 'einvoice_seller_additional_number',
        'seller_district'              => 'einvoice_seller_district',
        'seller_city'                  => 'einvoice_seller_city',
        'seller_province'              => 'einvoice_seller_province',
        'seller_postal_code'           => 'einvoice_seller_postal_code',
        'key_path'                     => 'einvoice_key_path',
        'certificate_path'             => 'einvoice_certificate_path',
        'exempt_category'              => 'einvoice_exempt_category',
        'exempt_reason_code'           => 'einvoice_exempt_reason_code',
        'exempt_reason_text'           => 'einvoice_exempt_reason_text',
    ];
    public const EXEMPTION_CODES = [
        'E' => ['VATEX-SA-29', 'VATEX-SA-29-7', 'VATEX-SA-30'],
        'Z' => ['VATEX-SA-32', 'VATEX-SA-33', 'VATEX-SA-34-1', 'VATEX-SA-34-2', 'VATEX-SA-34-3', 'VATEX-SA-34-4', 'VATEX-SA-34-5', 'VATEX-SA-35', 'VATEX-SA-36', 'VATEX-SA-EDU', 'VATEX-SA-HEA', 'VATEX-SA-MLTRY'],
        'O' => ['VATEX-SA-OOS'],
    ];

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private ?BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->config   = model(Appconfig::class);
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches;
    }

    public function isEnabled(): bool
    {
        return $this->config->get_value(self::ENABLED, '0') === '1' && (new AccountingSettings($this->db))->isEnabled();
    }

    /**
     * Whether the documents are queued to be sent to ZATCA (the second phase). Off until a unit is onboarded, which
     * needs a separate approval for each environment (D-10, D-20, D-21).
     */
    public function phaseTwo(): bool
    {
        return $this->config->get_value(self::PHASE_TWO, '0') === '1';
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $values = [];

        foreach (self::FIELDS as $field => $key) {
            $values[$field] = $this->config->get_value($key);
        }

        return $values;
    }

    public function sellerName(): string
    {
        return trim($this->config->get_value('company'));
    }

    public function sellerVatNumber(): string
    {
        return trim($this->config->get_value('tax_id'));
    }

    public function stored(): bool
    {
        return $this->config->get_value(self::ENABLED, '0') === '1';
    }

    /**
     * @param array<string, mixed> $input The enabled switch and the fields by name; one that is not in it stays as it is.
     *
     * @return array<string, string> What changed, by key.
     *
     * @throws AccountingException|BranchException|EInvoiceException
     */
    public function update(array $input, ?int $employeeId = null): array
    {
        $this->guard->require(AccountingGuard::EINVOICING_MANAGE, $employeeId);

        $this->branches ??= new BranchGuard($this->db);
        $this->branches->requireCompanyScope($employeeId);

        $current = $this->all();
        $merged  = $current;
        $before  = [self::ENABLED => $this->stored() ? '1' : '0'];
        $changes = [];

        foreach (self::FIELDS as $field => $key) {
            $before[$key] = $current[$field];
        }

        foreach (array_keys(self::FIELDS) as $field) {
            if (array_key_exists($field, $input)) {
                $merged[$field] = trim((string) $input[$field]);
            }
        }

        $this->validate($merged);

        foreach (self::FIELDS as $field => $key) {
            if ($merged[$field] !== $current[$field]) {
                $changes[$key] = $merged[$field];
            }
        }

        if (array_key_exists('enabled', $input)) {
            $enabled = (bool) $input['enabled'];

            if ($enabled && ! (new AccountingSettings($this->db))->isEnabled()) {
                throw new EInvoiceException(EInvoiceError::AccountingDisabled, 'Electronic invoicing needs accounting to be on.');
            }

            if (($enabled ? '1' : '0') !== $before[self::ENABLED]) {
                $changes[self::ENABLED] = $enabled ? '1' : '0';
            }
        }

        if ($changes === []) {
            return [];
        }

        if (array_filter(array_keys($changes), static fn (string $key): bool => str_starts_with($key, 'einvoice_seller_')) !== []) {
            (new CompanyTaxProfile($this->db, $this->guard, $this->branches))->requireSellerChange($employeeId);
        }

        if (! $this->config->batch_save($changes)) {
            throw new AccountingException(AccountingError::WriteFailed, 'The settings could not be saved.');
        }

        $this->audit->write('einvoice_settings', null, 'settings_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);

        return $changes;
    }

    /**
     * @param array<string, string> $values
     *
     * @throws EInvoiceException
     */
    private function validate(array $values): void
    {
        $type = $values['seller_identification_type'];

        if ($type !== '' && PartyIdentificationType::forSellerOrNull($type) === null) {
            throw new EInvoiceException(EInvoiceError::IdentificationInvalid, 'The seller cannot be identified with this scheme.', ['field' => $type]);
        }

        if ($values['seller_identification_number'] !== '' && ! EInvoiceRules::identificationNumber($values['seller_identification_number'])) {
            throw new EInvoiceException(EInvoiceError::IdentificationInvalid, 'An identification number holds letters and digits only.', ['field' => $values['seller_identification_number']]);
        }

        if ($values['seller_building_number'] !== '' && ! EInvoiceRules::buildingNumber($values['seller_building_number'])) {
            throw new EInvoiceException(EInvoiceError::AddressInvalid, 'A building number has four digits.', ['field' => $values['seller_building_number']]);
        }

        if ($values['seller_postal_code'] !== '' && ! EInvoiceRules::postalCode($values['seller_postal_code'])) {
            throw new EInvoiceException(EInvoiceError::AddressInvalid, 'A postal code has five digits.', ['field' => $values['seller_postal_code']]);
        }

        foreach (['seller_street' => EInvoiceRules::STREET_MAX, 'seller_additional_street' => EInvoiceRules::TEXT_MAX, 'seller_district' => EInvoiceRules::TEXT_MAX, 'seller_city' => EInvoiceRules::TEXT_MAX, 'seller_province' => EInvoiceRules::TEXT_MAX] as $field => $limit) {
            if (! EInvoiceRules::within($values[$field], $limit)) {
                throw new EInvoiceException(EInvoiceError::AddressInvalid, 'An address field is too long.', ['field' => $field, 'expected' => $limit]);
            }
        }

        foreach (['seller_additional_number'] as $field) {
            if ($values[$field] !== '' && preg_match('/^\d{1,10}$/', $values[$field]) !== 1) {
                throw new EInvoiceException(EInvoiceError::AddressInvalid, 'The additional number holds digits only.', ['field' => $field]);
            }
        }

        $category = $values['exempt_category'];
        $code     = $values['exempt_reason_code'];

        if ($category !== '' && ! isset(self::EXEMPTION_CODES[$category])) {
            throw new EInvoiceException(EInvoiceError::ExemptionInvalid, 'The category of a line without tax is Z, E or O.', ['field' => $category]);
        }

        if ($category === '' && ($code !== '' || $values['exempt_reason_text'] !== '')) {
            throw new EInvoiceException(EInvoiceError::ExemptionInvalid, 'A reason is kept only with a category.', ['field' => $code]);
        }

        if ($category !== '' && ! in_array($code, self::EXEMPTION_CODES[$category], true)) {
            throw new EInvoiceException(EInvoiceError::ExemptionInvalid, 'The reason code does not belong to the category.', ['field' => $code]);
        }

        if (! EInvoiceRules::within($values['exempt_reason_text'], EInvoiceRules::NOTE_MAX)) {
            throw new EInvoiceException(EInvoiceError::ExemptionInvalid, 'The reason text is too long.', ['field' => 'exempt_reason_text', 'expected' => EInvoiceRules::NOTE_MAX]);
        }

        foreach (['key_path', 'certificate_path'] as $field) {
            if ($values[$field] !== '') {
                KeyPath::check($values[$field]);
            }
        }
    }
}
