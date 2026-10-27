<?php

namespace App\Libraries\Accounting;

enum InvoiceDiscountProblem: string
{
    case NotGoodsIn       = 'invoice_discount_wrong_mode';
    case MoreThanTheLines = 'invoice_discount_invalid';
}
