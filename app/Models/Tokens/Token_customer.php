<?php

namespace App\Models\Tokens;

use App\Libraries\Sale_lib;
use App\Models\Customer;

/**
 * Token_customer class
 */
class Token_customer extends Token
{
    private ?array $customerInfo;
    private Sale_lib $saleLib;

    /**
     * @param array<string, mixed>|null $customerInfo The customer of the document the number is made for, with first_name and last_name; null takes the customer of the register.
     */
    public function __construct(?array $customerInfo = null)
    {
        parent::__construct();
        $this->customerInfo = $customerInfo;
        $this->saleLib      = new Sale_lib();
    }

    public function token_id(): string
    {
        return 'CU';
    }

    public function get_value(): string
    {
        if ($this->customerInfo !== null) {
            return trim(($this->customerInfo['first_name'] ?? '') . ' ' . ($this->customerInfo['last_name'] ?? ''));
        }

        $customerId = $this->saleLib->get_customer();

        if ($customerId != NEW_ITEM) {
            $customerInfo = model(Customer::class)->get_info($customerId);

            if ($customerInfo != '') {
                return trim($customerInfo->first_name . ' ' . $customerInfo->last_name);
            }
        }

        return '';
    }
}
