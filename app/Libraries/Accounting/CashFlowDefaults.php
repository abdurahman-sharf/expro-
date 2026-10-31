<?php

namespace App\Libraries\Accounting;

/**
 * The lines the cash flow report starts with, in the order the reference system draws them, and the
 * accounts each takes from until it is pointed at others: the net income takes from every revenue and
 * expense account, the others from the account the chart names or from the main account with that
 * code. The lines of the two investing accounts have no formula in the reference system, so the
 * sale of assets takes what was credited to them and the purchase of assets minus what was debited.
 */
final class CashFlowDefaults
{
    public const NET_INCOME = 'net_income';

    /**
     * @return list<array{key: string, section: CashFlowSection, formula: CashFlowFormula, systemKeys: list<string>, codes: list<string>}>
     */
    public static function lines(): array
    {
        return [
            ['key' => self::NET_INCOME, 'section' => CashFlowSection::Operating, 'formula' => CashFlowFormula::CreditInPeriod, 'systemKeys' => [], 'codes' => []],
            ['key' => 'depreciation_expense', 'section' => CashFlowSection::Operating, 'formula' => CashFlowFormula::DebitInPeriod, 'systemKeys' => [], 'codes' => ['53']],
            ['key' => 'account_receivable', 'section' => CashFlowSection::Operating, 'formula' => CashFlowFormula::CreditInPeriod, 'systemKeys' => ['receivables'], 'codes' => []],
            ['key' => 'account_payable', 'section' => CashFlowSection::Operating, 'formula' => CashFlowFormula::CreditInPeriod, 'systemKeys' => ['payables'], 'codes' => []],
            ['key' => 'inventory', 'section' => CashFlowSection::Operating, 'formula' => CashFlowFormula::CreditInPeriod, 'systemKeys' => ['inventory'], 'codes' => []],
            ['key' => 'assets_selling', 'section' => CashFlowSection::Investing, 'formula' => CashFlowFormula::CreditsInPeriod, 'systemKeys' => [], 'codes' => ['111']],
            ['key' => 'assets_purchases', 'section' => CashFlowSection::Investing, 'formula' => CashFlowFormula::MinusDebitsInPeriod, 'systemKeys' => [], 'codes' => ['111']],
            ['key' => 'common_shares', 'section' => CashFlowSection::Financing, 'formula' => CashFlowFormula::CreditInPeriod, 'systemKeys' => [], 'codes' => ['31']],
        ];
    }

    /**
     * @return array<string, array{key: string, section: CashFlowSection, formula: CashFlowFormula, systemKeys: list<string>, codes: list<string>}>
     */
    public static function byKey(): array
    {
        $lines = [];

        foreach (self::lines() as $line) {
            $lines[$line['key']] = $line;
        }

        return $lines;
    }
}
