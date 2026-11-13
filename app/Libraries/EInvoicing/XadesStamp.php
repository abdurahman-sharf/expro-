<?php

namespace App\Libraries\EInvoicing;

/**
 * The cryptographic stamp (KSA-15) as the UBL extension the security standard describes: an enveloped XAdES
 * signature of the baseline level with the two references, the transforms that take the extensions, the
 * signature and the code out of what is hashed, the certificate, and the signed properties that name the
 * certificate and the moment. A candidate: the policy identifier the standard's table mentions has no value
 * published in the documents read, and is not written. To be verified against the toolbox of the authority.
 */
class XadesStamp
{
    public const NS_SIG    = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';
    public const NS_SAC    = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';
    public const NS_SBC    = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';
    public const NS_DS     = 'http://www.w3.org/2000/09/xmldsig#';
    public const NS_XADES  = 'http://uri.etsi.org/01903/v1.3.2#';
    public const SIGNED_ID = 'xadesSignedProperties';
    public const DATA_ID   = 'invoiceSignedData';

    public function certificateDigest(string $certificateBase64): string
    {
        return base64_encode(hash('sha256', $certificateBase64));
    }

    public function extensions(
        string $documentHash,
        string $signatureValue,
        string $certificate,
        string $signingTime,
        string $certificateDigest,
        string $issuerName,
        string $serialNumber,
        string $propertiesDigest,
    ): string {
        $sha256 = '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>';

        $transforms = '<ds:Transforms>'
            . $this->xpathTransform('not(//ancestor-or-self::ext:UBLExtensions)')
            . $this->xpathTransform('not(//ancestor-or-self::cac:Signature)')
            . $this->xpathTransform("not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])")
            . '<ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
            . '</ds:Transforms>';

        $signedInfo = '<ds:SignedInfo>'
            . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
            . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>'
            . '<ds:Reference Id="' . self::DATA_ID . '" URI="">' . $transforms . $sha256 . '<ds:DigestValue>' . UblInvoiceXml::escape($documentHash) . '</ds:DigestValue></ds:Reference>'
            . '<ds:Reference Type="http://uri.etsi.org/01903#SignedProperties" URI="#' . self::SIGNED_ID . '">' . $sha256 . '<ds:DigestValue>' . UblInvoiceXml::escape($propertiesDigest) . '</ds:DigestValue></ds:Reference>'
            . '</ds:SignedInfo>';

        $properties = '<xades:SignedProperties Id="' . self::SIGNED_ID . '">'
            . '<xades:SignedSignatureProperties>'
            . '<xades:SigningTime>' . UblInvoiceXml::escape($signingTime) . '</xades:SigningTime>'
            . '<xades:SigningCertificate><xades:Cert>'
            . '<xades:CertDigest>' . $sha256 . '<ds:DigestValue>' . UblInvoiceXml::escape($certificateDigest) . '</ds:DigestValue></xades:CertDigest>'
            . '<xades:IssuerSerial><ds:X509IssuerName>' . UblInvoiceXml::escape($issuerName) . '</ds:X509IssuerName><ds:X509SerialNumber>' . UblInvoiceXml::escape($serialNumber) . '</ds:X509SerialNumber></xades:IssuerSerial>'
            . '</xades:Cert></xades:SigningCertificate>'
            . '</xades:SignedSignatureProperties>'
            . '<xades:SignedDataObjectProperties><xades:DataObjectFormat ObjectReference="#' . self::DATA_ID . '"><xades:MimeType>text/xml</xades:MimeType></xades:DataObjectFormat></xades:SignedDataObjectProperties>'
            . '</xades:SignedProperties>';

        $signature = '<ds:Signature xmlns:ds="' . self::NS_DS . '" Id="signature">'
            . $signedInfo
            . '<ds:SignatureValue>' . UblInvoiceXml::escape($signatureValue) . '</ds:SignatureValue>'
            . '<ds:KeyInfo><ds:X509Data><ds:X509Certificate>' . UblInvoiceXml::escape($certificate) . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo>'
            . '<ds:Object><xades:QualifyingProperties xmlns:xades="' . self::NS_XADES . '" Target="signature">' . $properties . '</xades:QualifyingProperties></ds:Object>'
            . '</ds:Signature>';

        return '<ext:UBLExtensions><ext:UBLExtension>'
            . '<ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>'
            . '<ext:ExtensionContent>'
            . '<sig:UBLDocumentSignatures xmlns:sig="' . self::NS_SIG . '" xmlns:sac="' . self::NS_SAC . '" xmlns:sbc="' . self::NS_SBC . '">'
            . '<sac:SignatureInformation>'
            . '<cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>'
            . '<sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>'
            . $signature
            . '</sac:SignatureInformation>'
            . '</sig:UBLDocumentSignatures>'
            . '</ext:ExtensionContent></ext:UBLExtension></ext:UBLExtensions>';
    }

    /**
     * The digest of the signed properties as the document holds them: the canonical form of the element in
     * its place in the file.
     *
     * @throws EInvoiceException
     */
    public function propertiesDigest(string $xml): string
    {
        $element = InvoiceHash::load($xml)->getElementsByTagNameNS(self::NS_XADES, 'SignedProperties')->item(0);

        if ($element === null) {
            throw new EInvoiceException(EInvoiceError::SigningFailed, 'The document holds no signed properties.');
        }

        return base64_encode(hash('sha256', (string) $element->C14N(false, false), true));
    }

    private function xpathTransform(string $expression): string
    {
        return '<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>' . UblInvoiceXml::escape($expression) . '</ds:XPath></ds:Transform>';
    }
}
