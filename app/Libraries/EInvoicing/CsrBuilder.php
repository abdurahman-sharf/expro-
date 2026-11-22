<?php

namespace App\Libraries\EInvoicing;

use SensitiveParameter;

/**
 * The certificate signing request of a unit as ZATCA asks for it (R-05, T0 item 24), made on this server: a new key
 * on the secp256k1 curve that never leaves it, and a request that names the country, the branch, the taxpayer and
 * the unit, and in its alternative name the serial of the unit (`1-…|2-…|3-…`), the VAT number, the kinds of
 * invoices it issues (`1100` for both), the address and the business. The template name tells the environment it is
 * meant for. Nothing is sent: the request goes to ZATCA only through onboarding, after the approval of that
 * environment.
 */
final class CsrBuilder
{
    public const TEMPLATES = [
        'sandbox'    => 'TSTZATCA-Code-Signing',
        'simulation' => 'PREZATCA-Code-Signing',
        'production' => 'ZATCA-Code-Signing',
    ];
    private const ALLOWED = '/^[\p{L}\p{N} .,\-\/|]+$/u';

    /**
     * @param array{common_name: string, organization: string, organization_unit: string, serial: string, vat_number: string, invoice_types: string, address: string, business: string} $unit
     *
     * @return array{csr: string, private_key: string}
     *
     * @throws EInvoiceException
     */
    public function build(string $environment, array $unit): array
    {
        if (! isset(self::TEMPLATES[$environment])) {
            throw new EInvoiceException(EInvoiceError::SettingInvalid, 'The environment is not one of ZATCA.', ['field' => $environment]);
        }

        foreach ($unit as $field => $value) {
            if (trim($value) === '' || preg_match(self::ALLOWED, $value) !== 1) {
                throw new EInvoiceException(EInvoiceError::SettingInvalid, 'A field of the request is empty or holds a character it may not.', ['field' => $field]);
            }
        }

        if (! EInvoiceRules::vatNumber($unit['vat_number']) || preg_match('/^[01]{4}$/', $unit['invoice_types']) !== 1 || preg_match('/^1-[^|]+\|2-[^|]+\|3-[^|]+$/', $unit['serial']) !== 1) {
            throw new EInvoiceException(EInvoiceError::SettingInvalid, 'The VAT number, the kinds of invoices or the serial of the unit is not in the form ZATCA asks for.');
        }

        if (! StampKeys::curveAvailable()) {
            throw new EInvoiceException(EInvoiceError::CurveUnsupported, 'The OpenSSL of this server has no secp256k1 curve.');
        }

        $config = tempnam(sys_get_temp_dir(), 'csr');

        try {
            file_put_contents($config, $this->config($environment, $unit));

            $options = ['config' => $config, 'req_extensions' => 'v3_req', 'digest_alg' => 'sha256'];
            $key     = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => StampKeys::CURVE]);
            $request = $key === false ? false : openssl_csr_new(['countryName' => 'SA', 'organizationalUnitName' => $unit['organization_unit'], 'organizationName' => $unit['organization'], 'commonName' => $unit['common_name']], $key, $options);

            if ($key === false || $request === false || ! openssl_csr_export($request, $csr) || ! openssl_pkey_export($key, $privateKey)) {
                throw new EInvoiceException(EInvoiceError::SigningFailed, 'The request could not be made.');
            }
        } finally {
            @unlink($config);
        }

        return ['csr' => $csr, 'private_key' => $privateKey];
    }

    /**
     * @param array<string, string> $unit
     */
    private function config(string $environment, #[SensitiveParameter] array $unit): string
    {
        return implode("\n", [
            '[req]',
            'prompt = no',
            'default_bits = 2048',
            'distinguished_name = dn',
            'req_extensions = v3_req',
            '[dn]',
            '[v3_req]',
            '1.3.6.1.4.1.311.20.2 = ASN1:PRINTABLESTRING:' . self::TEMPLATES[$environment],
            'subjectAltName = dirName:alt_names',
            '[alt_names]',
            'SN = ' . $unit['serial'],
            'UID = ' . $unit['vat_number'],
            'title = ' . $unit['invoice_types'],
            'registeredAddress = ' . $unit['address'],
            'businessCategory = ' . $unit['business'],
            '',
        ]);
    }
}
