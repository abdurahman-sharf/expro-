<?php

namespace App\Libraries\Pricing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;

/**
 * One line of a sale that sells under the minimum price of its item or gives more discount than is
 * allowed on it.
 */
final readonly class PriceViolation
{
    /**
     * @param numeric-string $net     What one unit sells for after the discount of the line and its share of the discount on the whole sale.
     * @param numeric-string $bound   The minimum price, or the highest discount in percent.
     * @param numeric-string $percent The discount the line carries, in percent of its price.
     */
    public function __construct(
        public PriceRule $rule,
        public int $line,
        public int $itemId,
        public string $name,
        public string $net,
        public string $bound,
        public string $percent,
    ) {
    }

    public function toException(): AccountingException
    {
        return match ($this->rule) {
            PriceRule::BelowMinimum => new AccountingException(
                AccountingError::PriceBelowMinimum,
                'The line sells under the minimum price of the item.',
                ['line' => $this->line, 'item_id' => $this->itemId, 'name' => $this->name, 'min' => bcadd($this->bound, '0', 2), 'net' => bcadd($this->net, '0', 2)],
            ),
            PriceRule::AboveCeiling => new AccountingException(
                AccountingError::DiscountAboveCeiling,
                'The line carries more discount than is allowed.',
                ['line' => $this->line, 'item_id' => $this->itemId, 'name' => $this->name, 'max' => bcadd($this->bound, '0', 2), 'percent' => bcadd($this->percent, '0', 2)],
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule->value, 'line' => $this->line, 'item_id' => $this->itemId, 'net' => bcadd($this->net, '0', 4), 'bound' => bcadd($this->bound, '0', 4), 'percent' => bcadd($this->percent, '0', 2)];
    }
}
