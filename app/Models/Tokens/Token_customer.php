<?php

namespace App\Models\Tokens;

use App\Libraries\Sale_lib;

use App\Models\Customer;

/**
 * Token_customer class
 **/
class Token_customer extends Token
{
    private ?array $customer_info;
    private Sale_lib $sale_lib;

    /**
     * @param array<string, mixed>|null $customer_info The customer of the document the number is made for, with first_name and last_name; null takes the customer of the register.
     */
    public function __construct(?array $customer_info = null)
    {
        parent::__construct();
        $this->customer_info = $customer_info;
        $this->sale_lib = new Sale_lib();
    }

    /**
     * @return string
     */
    public function token_id(): string
    {
        return 'CU';
    }

    /**
     * @return string
     */
    public function get_value(): string
    {
        if ($this->customer_info !== null) {
            return trim(($this->customer_info['first_name'] ?? '') . ' ' . ($this->customer_info['last_name'] ?? ''));
        }

        $customer_id = $this->sale_lib->get_customer();

        if ($customer_id != NEW_ITEM) {
            $customer_info = model(Customer::class)->get_info($customer_id);

            if ($customer_info != '') {
                return trim($customer_info->first_name . ' ' . $customer_info->last_name);
            }
        }

        return '';
    }
}
