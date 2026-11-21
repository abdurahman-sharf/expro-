<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\CsrBuilder;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\StampKeys;
use CodeIgniter\Test\CIUnitTestCase;

class CsrBuilderTest extends CIUnitTestCase
{
    private const UNIT = [
        'common_name'       => 'EGS1-886431145',
        'organization'      => 'Maximum Speed Tech Supply LTD',
        'organization_unit' => 'Riyadh Branch',
        'serial'            => '1-Expro|2-4.0|3-ed22f1d8-e6a2-1118-9b58-d9a8f11e445f',
        'vat_number'        => '399999999900003',
        'invoice_types'     => '1100',
        'address'           => 'RRRD2929',
        'business'          => 'Supply activities',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! StampKeys::curveAvailable()) {
            $this->markTestSkipped('The OpenSSL of this machine has no secp256k1 curve.');
        }
    }

    public function testBuild_MakesARequestWithTheFieldsZatcaAsksForOnANewKeyOfTheCurve(): void
    {
        $made    = (new CsrBuilder())->build('sandbox', self::UNIT);
        $subject = openssl_csr_get_subject($made['csr'], false);
        $key     = openssl_pkey_get_details(openssl_pkey_get_private($made['private_key']));
        $file    = tempnam(sys_get_temp_dir(), 'csr');

        file_put_contents($file, $made['csr']);

        $dump = (string) shell_exec('openssl req -noout -text -in ' . escapeshellarg($file) . ' 2>/dev/null');

        unlink($file);

        $this->assertSame(['SA', 'Riyadh Branch', 'Maximum Speed Tech Supply LTD', 'EGS1-886431145'], [$subject['countryName'], $subject['organizationalUnitName'], $subject['organizationName'], $subject['commonName']]);
        $this->assertSame('secp256k1', $key['ec']['curve_name']);

        $this->assertStringContainsString('TSTZATCA-Code-Signing', $dump);
        $this->assertStringContainsString('399999999900003', $dump);
        $this->assertStringContainsString('1-Expro|2-4.0|3-ed22f1d8', $dump);
    }

    public function testBuild_RefusesAnUnknownEnvironmentAndFieldsNotInTheForm(): void
    {
        foreach ([['local', self::UNIT], ['sandbox', [...self::UNIT, 'vat_number' => '123']], ['sandbox', [...self::UNIT, 'serial' => 'EGS-1']], ['sandbox', [...self::UNIT, 'organization' => 'Bad = name']]] as [$environment, $unit]) {
            try {
                (new CsrBuilder())->build($environment, $unit);
                $this->fail('The request was made.');
            } catch (EInvoiceException $refusal) {
                $this->assertSame(EInvoiceError::SettingInvalid, $refusal->error);
            }
        }
    }
}
