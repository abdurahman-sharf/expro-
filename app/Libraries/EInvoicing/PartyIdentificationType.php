<?php

namespace App\Libraries\EInvoicing;

/**
 * The identification schemes of a party in the electronic invoice: the eleven codes the reference system
 * offers for a customer, which the specification lists for the buyer (BT-46-1), and the six of them it lets
 * the seller use (BT-29-1, in the order the specification gives them).
 */
enum PartyIdentificationType: string
{
    case CommercialRegistration = 'CRN';
    case MunicipalLicense       = 'MOM';
    case LaborLicense           = 'MLS';
    case InvestmentLicense      = 'SAG';
    case SevenHundredNumber     = '700';
    case TaxIdentification      = 'TIN';
    case NationalId             = 'NAT';
    case Iqama                  = 'IQA';
    case GulfId                 = 'GCC';
    case Passport               = 'PAS';
    case Other                  = 'OTH';

    private const SELLER_ORDER = ['CRN', 'MOM', 'MLS', '700', 'SAG', 'OTH'];

    public function languageKey(): string
    {
        return 'Accounting_einvoicing.identification_' . $this->value;
    }

    public function allowedForSeller(): bool
    {
        return in_array($this->value, self::SELLER_ORDER, true);
    }

    /**
     * @return list<self>
     */
    public static function forSeller(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->allowedForSeller()));
    }

    public static function forSellerOrNull(string $code): ?self
    {
        $type = self::tryFrom($code);

        return $type !== null && $type->allowedForSeller() ? $type : null;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
