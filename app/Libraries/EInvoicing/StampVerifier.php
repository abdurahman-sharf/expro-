<?php

namespace App\Libraries\EInvoicing;

use DOMXPath;

/**
 * Checks a stamped file from what it holds alone, as a reader of the file would: the digest of the
 * document, the digest of the signed properties and the signature over the hash, with the key of the
 * certificate the file carries. It says what failed and never throws for a file that is merely wrong.
 */
final class StampVerifier
{
    public function __construct(private readonly EcdsaSigner $signer = new EcdsaSigner(), private readonly XadesStamp $stamp = new XadesStamp())
    {
    }

    /**
     * @return array{signed: bool, document: bool, properties: bool, signature: bool}
     */
    public function verify(string $xml): array
    {
        $result = ['signed' => false, 'document' => false, 'properties' => false, 'signature' => false];

        try {
            $document = InvoiceHash::load($xml);
        } catch (EInvoiceException) {
            return $result;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', XadesStamp::NS_DS);

        $signatureValue = $this->text($xpath, '//ds:Signature/ds:SignatureValue');
        $certificate    = $this->text($xpath, '//ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate');

        if ($signatureValue === '' || $certificate === '') {
            return $result;
        }

        $result['signed'] = true;

        try {
            $hash = InvoiceHash::bytes($xml);

            $result['document']   = $this->text($xpath, "//ds:Reference[@Id='" . XadesStamp::DATA_ID . "']/ds:DigestValue") === base64_encode($hash);
            $result['properties'] = $this->text($xpath, "//ds:Reference[@URI='#" . XadesStamp::SIGNED_ID . "']/ds:DigestValue") === $this->stamp->propertiesDigest($xml);
        } catch (EInvoiceException) {
            return $result;
        }

        $key = openssl_pkey_get_public("-----BEGIN CERTIFICATE-----\n" . chunk_split($certificate, 64, "\n") . "-----END CERTIFICATE-----\n");

        if ($key === false) {
            return $result;
        }

        $details = openssl_pkey_get_details($key);
        $der     = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', (string) ($details['key'] ?? '')) ?? '', true);

        $result['signature'] = $der !== false && $this->signer->verify($hash, (string) base64_decode($signatureValue, true), $der);

        return $result;
    }

    private function text(DOMXPath $xpath, string $expression): string
    {
        $nodes = $xpath->query($expression);

        return $nodes === false || $nodes->length === 0 ? '' : trim((string) $nodes->item(0)?->textContent);
    }
}
