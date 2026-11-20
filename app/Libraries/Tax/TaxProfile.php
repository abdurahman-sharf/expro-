<?php

namespace App\Libraries\Tax;

/**
 * The tax identity of a company as it stands: whether it is registered for VAT (unknown while nobody has said),
 * its VAT number, and the Saudi mode with the moment it takes effect.
 */
final readonly class TaxProfile
{
    public function __construct(
        public int $companyId,
        public VatRegistration $registration,
        public string $vatNumber,
        public bool $saudiMode,
        public ?string $effectiveAt = null,
        public ?string $activatedAt = null,
        public ?int $activatedBy = null,
        public ?int $watermark = null,
    ) {
    }

    /**
     * Whether the Saudi rules apply at a moment: the mode is on and the moment has reached its effective date.
     */
    public function inSaudiModeAt(string $moment): bool
    {
        return $this->saudiMode && $this->effectiveAt !== null && strcmp($moment, $this->effectiveAt) >= 0;
    }
}
