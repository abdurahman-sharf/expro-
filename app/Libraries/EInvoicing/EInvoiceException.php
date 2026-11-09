<?php

namespace App\Libraries\EInvoicing;

use RuntimeException;
use Throwable;

class EInvoiceException extends RuntimeException
{
    private const WORDS = ['identification_number', 'street', 'additional_street', 'building_number', 'district', 'city', 'province', 'postal_code', 'journal_total', 'journal_tax', 'journal_revenue', 'total', 'tax'];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly EInvoiceError $error,
        string $message = '',
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $error->value, 0, $previous);
    }

    public function describe(): string
    {
        $arguments = [];

        $labelled = $this->labelled();

        foreach (['rule', 'field', 'expected', 'actual', 'name', 'count', 'item', 'line', 'number'] as $name) {
            if (array_key_exists($name, $this->context)) {
                $arguments[] = in_array($name, $labelled, true) ? $this->word((string) $this->context[$name]) : (string) $this->context[$name];
            }
        }

        return lang('Accounting_einvoicing.error_' . $this->error->value, $arguments);
    }

    /**
     * @return list<string> The context entries that hold the name of a field or of a figure, which is said in the language of the reader.
     */
    private function labelled(): array
    {
        return match ($this->error) {
            EInvoiceError::SellerAddressIncomplete, EInvoiceError::BuyerAddressIncomplete => ['field'],
            EInvoiceError::AddressInvalid, EInvoiceError::IdentificationInvalid => array_key_exists('expected', $this->context) ? ['field'] : [],
            EInvoiceError::TotalsDoNotTie => ['rule'],
            default                       => [],
        };
    }

    private function word(string $label): string
    {
        $word = str_replace(' ', '_', strtolower($label));
        $word = str_starts_with($word, 'seller_') ? substr($word, 7) : $word;

        return in_array($word, self::WORDS, true) ? lang('Accounting_einvoicing.word_' . $word) : $label;
    }
}
