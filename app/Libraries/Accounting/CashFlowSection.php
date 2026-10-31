<?php

namespace App\Libraries\Accounting;

/**
 * The three parts the cash flow report is drawn in: what the business did, what it bought and sold
 * to keep going, and how it was paid for.
 */
enum CashFlowSection: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';
}
