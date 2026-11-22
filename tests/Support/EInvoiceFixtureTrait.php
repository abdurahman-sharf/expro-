<?php

namespace Tests\Support;

use App\Libraries\EInvoicing\EInvoiceAddress;
use App\Libraries\EInvoicing\EInvoiceDocument;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoiceLine;
use App\Libraries\EInvoicing\EInvoiceParty;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use App\Libraries\EInvoicing\EInvoiceTaxSubtotal;
use App\Libraries\EInvoicing\InvoiceHash;
use App\Libraries\EInvoicing\PartyIdentificationType;

/**
 * What the tests of the electronic invoice share: documents written out by hand, so that the generator and
 * the stamp are tested without a sale, and keys made at the time of the test in a directory of their own,
 * outside the application, that is removed when the test ends.
 */
trait EInvoiceFixtureTrait
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/einvoice_' . bin2hex(random_bytes(6));

        mkdir($directory, 0700, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    protected function removeTemporaryDirectories(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }

        $this->temporaryDirectories = [];
    }

    /**
     * A key on the curve of the standard (or another) with a certificate of its own, written in a directory
     * outside the application.
     *
     * @param array<string, string> $subject What the subject of the certificate holds instead of the defaults.
     *
     * @return array{key: string, certificate: string, directory: string}
     */
    protected function generateKeyPair(string $curve = 'secp256k1', ?string $directory = null, array $subject = []): array
    {
        $directory ??= $this->temporaryDirectory();
        $key         = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);

        $this->assertNotFalse($key, 'The key could not be generated.');

        $request     = openssl_csr_new(array_merge(['commonName' => 'Test EGS unit', 'organizationName' => 'Test taxpayer', 'countryName' => 'SA'], $subject), $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($request, null, $key, 30, ['digest_alg' => 'sha256'], random_int(1000, 999999));

        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($certificate, $certificatePem);

        $keyFile         = $directory . '/' . $curve . '_key.pem';
        $certificateFile = $directory . '/' . $curve . '_cert.pem';

        file_put_contents($keyFile, $keyPem);
        chmod($keyFile, 0600);
        file_put_contents($certificateFile, $certificatePem);

        return ['key' => $keyFile, 'certificate' => $certificateFile, 'directory' => $directory];
    }

    protected function sampleSeller(): EInvoiceParty
    {
        return new EInvoiceParty(
            'Maximum Speed Tech Supply LTD',
            '399999999900003',
            PartyIdentificationType::CommercialRegistration,
            '1010010000',
            new EInvoiceAddress('Prince Sultan', '1234', 'Al-Murabba', 'Riyadh', '12345', 'SA'),
        );
    }

    protected function sampleBuyer(): EInvoiceParty
    {
        return new EInvoiceParty(
            'Buyer Co',
            '311111111111113',
            null,
            '',
            new EInvoiceAddress('King Fahd Rd', '4321', 'Olaya', 'Riyadh', '11564', 'SA'),
        );
    }

    protected function simplifiedDocument(): EInvoiceDocument
    {
        $standard = static fn (int $id, string $name, string $quantity, string $price, string $allowance, string $net, string $tax): EInvoiceLine => new EInvoiceLine($id, $name, $quantity, 'PCE', $price, $allowance, $net, 'S', '15.00', $tax, bcadd($net, $tax, 2));

        return new EInvoiceDocument(
            kind: EInvoiceKind::Invoice,
            subtype: EInvoiceSubtype::Simplified,
            number: 'SME00010',
            issueDate: '2022-08-17',
            issueTime: '17:41:08',
            supplyDate: '2022-08-17',
            seller: $this->sampleSeller(),
            buyer: new EInvoiceParty('Cash customer'),
            lines: [$standard(1, 'Book', '2.00', '3.0000', '0.00', '6.00', '0.90'), $standard(2, 'Pen', '1.00', '20.0000', '5.00', '15.00', '2.25')],
            taxSubtotals: [new EInvoiceTaxSubtotal('S', '15.00', '21.00', '3.15')],
            lineExtension: '21.00',
            taxExclusive: '21.00',
            taxAmount: '3.15',
            taxInclusive: '24.15',
            paymentMeansCodes: ['10'],
            uuid: '8e6000cf-1a98-4174-b3e7-b5d5954bc10d',
            icv: 10,
            previousHash: InvoiceHash::FIRST,
        );
    }

    protected function creditNoteDocument(): EInvoiceDocument
    {
        return new EInvoiceDocument(
            kind: EInvoiceKind::CreditNote,
            subtype: EInvoiceSubtype::Standard,
            number: 'POS 77',
            issueDate: '2022-08-18',
            issueTime: '09:05:30',
            supplyDate: '2022-08-18',
            seller: $this->sampleSeller(),
            buyer: $this->sampleBuyer(),
            lines: [new EInvoiceLine(1, 'Book', '2.00', 'PCE', '3.0000', '0.00', '6.00', 'S', '15.00', '0.90', '6.90')],
            taxSubtotals: [new EInvoiceTaxSubtotal('S', '15.00', '6.00', '0.90')],
            lineExtension: '6.00',
            taxExclusive: '6.00',
            taxAmount: '0.90',
            taxInclusive: '6.90',
            reason: 'In case of goods or services refund.',
            billingReference: 'INV-5',
            uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be',
            icv: 11,
            previousHash: 'UHJldmlvdXNIYXNo',
        );
    }

    /**
     * @param array<string, mixed> $changes The properties to change, by name.
     */
    protected function amended(EInvoiceDocument $base, array $changes): EInvoiceDocument
    {
        return new EInvoiceDocument(...array_merge(get_object_vars($base), $changes));
    }

    protected function skipWithoutTheCurve(): void
    {
        if (! in_array('secp256k1', openssl_get_curve_names() ?: [], true)) {
            $this->markTestSkipped('The installed OpenSSL does not offer secp256k1.');
        }
    }
}
