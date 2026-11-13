<?php

namespace App\Libraries\EInvoicing;

use DOMDocument;
use DOMXPath;

/**
 * The hash of a document as the standard takes it (the signing steps of the technical guidelines, and the
 * business rule of the previous invoice hash): the extensions, the signature and the reference that holds
 * the code are taken out, the rest is canonicalized, hashed with SHA-256 and written as base64. A document
 * that is not signed and one that is give the same hash, because the file holds no whitespace between its
 * elements.
 *
 * PHP has no canonicalization of version 1.1. It differs from the inclusive canonicalization of version 1.0
 * only for the attributes of the xml namespace (base, id, lang and space), which the file never carries and
 * which this refuses, so the bytes are those the standard names. To be verified against the toolbox of the
 * authority before any real use.
 */
final class InvoiceHash
{
    public const FIRST    = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';
    private const REMOVED = [
        "/*[local-name()='Invoice']//*[local-name()='UBLExtensions']",
        "/*[local-name()='Invoice']//*[local-name()='Signature']",
        "/*[local-name()='Invoice']//*[local-name()='AdditionalDocumentReference'][cbc:ID[normalize-space(text()) = 'QR']]",
    ];

    /**
     * @throws EInvoiceException
     */
    public static function canonical(string $xml): string
    {
        $document = self::load($xml);
        $xpath    = new DOMXPath($document);

        $xpath->registerNamespace('cbc', UblInvoiceXml::NS_CBC);

        foreach (self::REMOVED as $expression) {
            foreach (iterator_to_array($xpath->query($expression) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return (string) $document->C14N(false, false);
    }

    /**
     * @throws EInvoiceException
     */
    public static function bytes(string $xml): string
    {
        return hash('sha256', self::canonical($xml), true);
    }

    /**
     * @throws EInvoiceException
     */
    public static function of(string $xml): string
    {
        return base64_encode(self::bytes($xml));
    }

    /**
     * @throws EInvoiceException
     */
    public static function load(string $xml): DOMDocument
    {
        $document                     = new DOMDocument();
        $document->preserveWhiteSpace = true;
        $previous                     = libxml_use_internal_errors(true);
        $loaded                       = $document->loadXML($xml, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new EInvoiceException(EInvoiceError::RuleBroken, 'The document is not well formed XML.', ['rule' => 'XML']);
        }

        foreach ((new DOMXPath($document))->query('//@*[namespace-uri() = "http://www.w3.org/XML/1998/namespace"]') ?: [] as $attribute) {
            throw new EInvoiceException(EInvoiceError::RuleBroken, 'The canonicalization of version 1.1 is not available for attributes of the xml namespace: ' . $attribute->nodeName, ['rule' => 'C14N11']);
        }

        return $document;
    }
}
