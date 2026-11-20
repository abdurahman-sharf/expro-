<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Tax\CompanyTaxProfile;
use App\Libraries\Tax\VatRegistration;

/**
 * What a new installation still holds from the installer, and a business must change before it
 * uses the system: the password of the account the installer creates, which is published, and the
 * texts, formats and switches the installer writes, which print on every document until somebody
 * changes them. Both only read.
 */
final class DeploymentChecks extends CheckGroup
{
    /**
     * The password of the account the installer writes (initial_schema.sql), published with the
     * project. It is here because only the password itself can show that an account still has it
     * whatever salt its hash was written with, and it is never printed.
     */
    public const INSTALLER_PASSWORD = 'pointofsale';

    private const PLACEHOLDERS = [
        'company'                  => ['Open Source Point of Sale', 'the company name'],
        'address'                  => ['123 Nowhere street', 'the company address'],
        'phone'                    => ['555-555-5555', 'the company phone'],
        'email'                    => ['changeme@example.com', 'the company e-mail'],
        'return_policy'            => ['Test', 'the return policy'],
        'invoice_default_comments' => ['This is a default comment', 'the default comment of invoices'],
        'quote_default_comments'   => ['This is a default quote comment', 'the default comment of quotes'],
        'currency_symbol'          => ['$', 'the currency symbol'],
        'dateformat'               => ['m/d/Y', 'the date format'],
        'timezone'                 => ['America/New_York', 'the time zone'],
    ];
    private const NUMBERED_DOCUMENTS = [DocumentType::SalesInvoice, DocumentType::CreditNote, DocumentType::RefundInvoice, DocumentType::SalesQuote, DocumentType::Purchase];

    public function run(): array
    {
        return [
            $this->check('admin_default_password', 'ADMIN-DEFAULT-PASSWORD', 'Accounts that still have the installer password', ['employees.username', 'employees.password', 'employees.hash_version', 'employees.deleted'], fn (): CheckResult => $this->installerPassword()),
            $this->check('first_run_settings', 'FIRST-RUN-SETTINGS', 'Settings that still hold what the installer wrote', ['app_config'], fn (): CheckResult => $this->firstRunSettings()),
        ];
    }

    private function installerPassword(): CheckResult
    {
        $accounts = $this->db->table('employees')->select('username, password, hash_version')->where('deleted', 0)->orderBy('person_id', 'ASC')->get()->getResult();
        $found    = [];

        foreach ($accounts as $account) {
            if ($this->hasInstallerPassword((string) $account->password, (string) $account->hash_version)) {
                $found[] = (string) $account->username;
            }
        }

        $data = ['accounts' => count($accounts), 'with_installer_password' => count($found)];

        if ($found === []) {
            return CheckResult::pass('No account still has the installer password (' . count($accounts) . ' checked).', [], $data);
        }

        return CheckResult::fail(
            count($found) . ' account(s) still have the password the installer gave them, which is published: whoever finds the address of the system can sign in as them. Change it from the employee form.',
            array_map(static fn (string $username): string => "the account {$username} still has the installer password", $found),
            $data,
        );
    }

    private function hasInstallerPassword(string $stored, string $hashVersion): bool
    {
        return $hashVersion === '1' ? hash_equals($stored, md5(self::INSTALLER_PASSWORD)) : password_verify(self::INSTALLER_PASSWORD, $stored);
    }

    private function firstRunSettings(): CheckResult
    {
        $stored = [];

        foreach ($this->db->table('app_config')->select('`key`, value', false)->whereIn('key', [...array_keys(self::PLACEHOLDERS), 'tax_id'])->get()->getResult() as $row) {
            $stored[(string) $row->key] = trim((string) $row->value);
        }

        $placeholders = [];
        $findings     = [];

        foreach (self::PLACEHOLDERS as $key => [$installer, $name]) {
            if (($stored[$key] ?? null) === $installer) {
                $placeholders[] = $key;
                $findings[]     = "{$name} ({$key}) is still \"{$installer}\"";
            }
        }

        $noTaxNumber = ($stored['tax_id'] ?? '') === '' && (new CompanyTaxProfile($this->db))->current()->registration !== VatRegistration::NotRegistered;

        if ($noTaxNumber) {
            $findings[] = 'the tax number (tax_id) is empty, so the code of the simplified tax invoice is not printed';
        }

        $unnumbered = $this->unnumbered();

        if ($unnumbered !== []) {
            $findings[] = 'the numbering of ' . implode(', ', $unnumbered) . ' is switched off, so a document shows its own record number (the first invoice prints 0) until the sequence is switched on with the start number the books continue from';
        }

        $data = ['placeholders' => $placeholders, 'tax_number_empty' => $noTaxNumber, 'numbering_off' => $unnumbered];

        if ($findings === []) {
            return CheckResult::pass('The company texts and formats, the tax number and the numbering of the main documents have all been set.', [], $data);
        }

        return CheckResult::review(
            count($findings) . ' first-run setting(s) still hold what the installer wrote and print on every document: the owner sets them before the first invoice (the checklist is in docs/ui-redesign/deploy-checklist.md).',
            $findings,
            $data,
        );
    }

    /**
     * @return list<string>
     */
    private function unnumbered(): array
    {
        if (! $this->schema->hasTable('document_sequences')) {
            return [];
        }

        $numbering = new DocumentNumbering($this->db);

        return array_values(array_map(
            static fn (DocumentType $type): string => $type->value,
            array_filter(self::NUMBERED_DOCUMENTS, static fn (DocumentType $type): bool => ! $numbering->isEnabled($type)),
        ));
    }
}
