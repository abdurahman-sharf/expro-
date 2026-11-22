<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Documents\DocumentException;
use App\Libraries\Documents\SimplifiedInvoiceQr;
use SensitiveParameter;

/**
 * Turns a document with its counter and its previous hash into the file that is stored: the file and its
 * hash, and the code, either of the first phase when no key is in place, or stamped with the signature, the
 * public key and, for a simplified invoice, the signature of the certificate. The hash of a stamped file is
 * the one of the file before it was stamped, which is checked, because the hash of one invoice is the
 * previous hash of the next. The total in the code (tag 4) is the amount due (BT-115), as the official validator
 * (SDK 3.4.8) asks; it is the total with VAT unless a prepayment is taken off it or a rounding added to it.
 */
final class DocumentStamper
{
    public function __construct(
        private readonly UblInvoiceXml $xml = new UblInvoiceXml(),
        private readonly SimplifiedInvoiceQr $qr = new SimplifiedInvoiceQr(),
        private readonly EcdsaSigner $signer = new EcdsaSigner(),
        private readonly XadesStamp $stamp = new XadesStamp(),
    ) {
    }

    /**
     * @throws EInvoiceException
     */
    public function unsigned(EInvoiceDocument $document): StampedDocument
    {
        $qr   = $this->phaseOne($document);
        $file = $this->xml->build($document, $qr);

        return new StampedDocument($file, InvoiceHash::of($file), $qr, false);
    }

    /**
     * @param string $signingTime Universal time as 2026-10-22T09:47:29Z.
     *
     * @throws EInvoiceException
     */
    public function signed(EInvoiceDocument $document, #[SensitiveParameter] StampMaterial $material, string $signingTime): StampedDocument
    {
        $provisional = $this->phaseOne($document);
        $unstamped   = $this->xml->build($document, $provisional);
        $hash        = InvoiceHash::of($unstamped);
        $signature   = base64_encode($this->signer->sign(InvoiceHash::bytes($unstamped), $material));
        $digest      = $this->stamp->certificateDigest($material->certificateBase64);

        $draft = $this->xml->build($document, $provisional, $this->stamp->extensions($hash, $signature, $material->certificateBase64, $signingTime, $digest, $material->issuerName, $material->serialNumber, 'pending'));
        $props = $this->stamp->propertiesDigest($draft);

        try {
            $qr = $this->qr->encodePhaseTwo(
                $document->seller->name,
                $document->seller->vatNumber,
                $document->timestamp(),
                $document->payable(),
                $document->taxAmount,
                $hash,
                $signature,
                $material->publicKeyDer,
                $document->subtype === EInvoiceSubtype::Simplified ? $material->certificateSignatureDer : null,
            );
        } catch (DocumentException $failure) {
            throw new EInvoiceException(EInvoiceError::QrTooLong, 'The code of the document does not fit.', ['count' => (string) ($failure->context['bytes'] ?? '')], $failure);
        }

        $final = $this->xml->build($document, $qr, $this->stamp->extensions($hash, $signature, $material->certificateBase64, $signingTime, $digest, $material->issuerName, $material->serialNumber, $props));

        if (InvoiceHash::of($final) !== $hash) {
            throw new EInvoiceException(EInvoiceError::SigningFailed, 'The hash of the stamped file is not the hash that was signed.');
        }

        return new StampedDocument($final, $hash, $qr, true, $signature);
    }

    /**
     * @throws EInvoiceException
     */
    private function phaseOne(EInvoiceDocument $document): string
    {
        try {
            return $this->qr->encode($document->seller->name, $document->seller->vatNumber, $document->timestamp(), $document->payable(), $document->taxAmount);
        } catch (DocumentException $failure) {
            throw new EInvoiceException(EInvoiceError::SettingInvalid, 'The code of the document cannot be written: ' . $failure->error->value, ['field' => $failure->error->value], $failure);
        }
    }
}
