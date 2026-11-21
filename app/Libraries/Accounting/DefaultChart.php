<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;

final class DefaultChart
{
    public function __construct(private BaseConnection $db)
    {
    }

    /**
     * Installs the default chart, its routing rows and the manual number series.
     * Does nothing when the chart already has accounts.
     */
    public function install(): bool
    {
        if ($this->db->table('accounting_accounts')->countAllResults() > 0) {
            return false;
        }

        $this->db->transStart();

        $accountIds = [];

        foreach (self::accounts() as [$code, $name, $type, $parentCode, $isGroup, $systemKey, $contra]) {
            $normalBalance = $contra
                ? ($type->normalBalance() === NormalBalance::Debit ? NormalBalance::Credit : NormalBalance::Debit)
                : $type->normalBalance();

            $this->db->table('accounting_accounts')->insert([
                'parent_id'      => $parentCode === null ? null : $accountIds[$parentCode],
                'code'           => $code,
                'name'           => $name,
                'account_type'   => $type->value,
                'normal_balance' => $normalBalance->value,
                'is_group'       => (int) $isGroup,
                'is_system'      => (int) ($parentCode === null || $systemKey !== null),
                'system_key'     => $systemKey,
                'active'         => 1,
            ]);

            $accountIds[$code] = $this->db->insertID();
        }

        $accountIdsByKey = $this->accountIdsBySystemKey();

        foreach (RoutingKey::cases() as $routingKey) {
            $systemKey = $routingKey->defaultSystemKey();

            $this->db->table('accounting_routing')->ignore(true)->insert([
                'routing_key'  => $routingKey->value,
                'routing_type' => $routingKey->defaultType()->value,
                'account_id'   => $systemKey === null ? null : ($accountIdsByKey[$systemKey] ?? null),
            ]);
        }

        foreach (PaymentType::cases() as $paymentType) {
            $systemKey = $paymentType->defaultSystemKey();

            if ($systemKey !== null) {
                $this->db->table('accounting_routing')->ignore(true)->insert([
                    'routing_key' => $paymentType->routingKey(),
                    'account_id'  => $accountIdsByKey[$systemKey] ?? null,
                ]);
            }
        }

        $this->db->table('accounting_sequences')->ignore(true)->insert(['series' => 'manual', 'last_number' => 0]);

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * @return array<string, int>
     */
    private function accountIdsBySystemKey(): array
    {
        $rows = $this->db->table('accounting_accounts')
            ->select('account_id, system_key')
            ->where('system_key IS NOT NULL')
            ->get()
            ->getResultArray();

        return array_column($rows, 'account_id', 'system_key');
    }

    /**
     * The chart of the reference system as observed in a fresh account, plus the accounts only
     * this application needs: unused gift cards (216), the customers of sales made without naming
     * one (12043), the leaf accounts of the cheque cycle under the two groups of notes (120601,
     * 120602 and 212001) and the deposit fees (546), and the adjustments and the shipping charged on
     * sales (4204 and 4208), and the gains and losses of exchange differences (4209 and 5410). Rows are
     * [code, name, type, parent code, is group, system key, contra account].
     *
     * @return list<array{string, string, AccountType, ?string, bool, ?string, bool}>
     */
    private static function accounts(): array
    {
        return [
            ['1', 'الأصول', AccountType::Asset, null, true, null, false],
            ['2', 'الخصوم', AccountType::Liability, null, true, null, false],
            ['3', 'رأس المال وحقوق الملكية', AccountType::Equity, null, true, null, false],
            ['4', 'الإيرادات', AccountType::Revenue, null, true, null, false],
            ['5', 'المصروفات', AccountType::Expense, null, true, null, false],

            ['11', 'الأصول الثابتة', AccountType::Asset, '1', true, null, false],
            ['111', 'حسابات الأصول الثابتة', AccountType::Asset, '11', true, null, false],
            ['111001', 'أثاث', AccountType::Asset, '111', true, null, false],
            ['111002', 'الأجهزة والمعدات', AccountType::Asset, '111', true, null, false],
            ['111003', 'وسائل النقل', AccountType::Asset, '111', true, null, false],
            ['111004', 'مباني', AccountType::Asset, '111', true, null, false],
            ['111005', 'أراضي', AccountType::Asset, '111', true, null, false],
            ['112', 'مجمع الإهلاك', AccountType::Asset, '11', true, null, true],
            ['112001', 'مجمع إهلاك - أثاث', AccountType::Asset, '112', true, null, true],
            ['112002', 'مجمع إهلاك - الأجهزة والمعدات', AccountType::Asset, '112', true, null, true],
            ['112003', 'مجمع إهلاك - وسائل النقل', AccountType::Asset, '112', true, null, true],
            ['112004', 'مجمع إهلاك - مباني', AccountType::Asset, '112', true, null, true],
            ['112005', 'مجمع إهلاك - أراضي', AccountType::Asset, '112', true, null, true],
            ['111006', 'أصول ثابتة', AccountType::Asset, '111', false, 'fixed_assets', false],
            ['112006', 'مجمع إهلاك - عام', AccountType::Asset, '112', false, 'accumulated_depreciation', true],

            ['12', 'الأصول المتداولة', AccountType::Asset, '1', true, null, false],
            ['1201', 'الخزينة', AccountType::Asset, '12', true, 'treasuries', false],
            ['120101', 'الخزينة الأساسية', AccountType::Asset, '1201', false, 'cash', false],
            ['1202', 'البنك', AccountType::Asset, '12', true, 'banks', false],
            ['1203', 'المخزون', AccountType::Asset, '12', true, 'inventory', false],
            ['1204', 'المدينون', AccountType::Asset, '12', true, null, false],
            ['12041', 'العملاء', AccountType::Asset, '1204', true, 'receivables', false],
            ['12042', 'أطراف مدينة أخرى', AccountType::Asset, '1204', false, 'other_receivables', false],
            ['12043', 'عملاء نقديون', AccountType::Asset, '1204', false, 'counter_customers', false],
            ['12044', 'ذمم بين الفروع (مدينة)', AccountType::Asset, '1204', false, 'branches_receivable', false],
            ['1205', 'عهد الموظفين', AccountType::Asset, '12', true, null, false],
            ['1206', 'أوراق القبض', AccountType::Asset, '12', true, null, false],
            ['120601', 'أوراق القبض', AccountType::Asset, '1206', false, 'notes_receivable', false],
            ['120602', 'مبالغ تحت التحصيل', AccountType::Asset, '1206', false, 'cheques_under_collection', false],
            ['1208', 'عجز & زيادة الصندوق', AccountType::Asset, '12', false, 'cash_difference', false],
            ['1209', 'تغيير عملة', AccountType::Asset, '12', false, null, false],
            ['1210', 'المشتريات', AccountType::Asset, '12', false, 'purchases', false],

            ['21', 'الخصوم المتداولة', AccountType::Liability, '2', true, null, false],
            ['211', 'الدائنون', AccountType::Liability, '21', true, null, false],
            ['2211', 'الموردون', AccountType::Liability, '211', true, 'payables', false],
            ['2212', 'شركات الشحن', AccountType::Liability, '211', true, null, false],
            ['2213', 'أطراف دائنة أخرى', AccountType::Liability, '211', false, 'other_payables', false],
            ['2214', 'ذمم بين الفروع (دائنة)', AccountType::Liability, '211', false, 'branches_payable', false],
            ['212', 'أوراق الدفع', AccountType::Liability, '21', true, null, false],
            ['212001', 'أوراق الدفع', AccountType::Liability, '212', false, 'notes_payable', false],
            ['214', 'أرصدة افتتاحية', AccountType::Liability, '21', false, 'opening_balance', false],
            ['215', 'القيمة المضافة المطلوبة', AccountType::Liability, '21', true, null, false],
            ['215001', 'القيمة المضافة المحصلة', AccountType::Liability, '215', false, 'tax_output', false],
            ['215002', 'القيمة المضافة المدفوعة', AccountType::Liability, '215', false, 'tax_input', false],
            ['215010', 'ضريبة الدفعات المقدمة من العملاء', AccountType::Liability, '215', false, 'tax_advances', false],
            ['216', 'بطاقات الهدايا غير المستخدمة', AccountType::Liability, '21', false, 'giftcard_liability', false],
            ['22', 'الخصوم طويلة الأجل', AccountType::Liability, '2', true, null, false],

            ['31', 'رأس المال', AccountType::Equity, '3', false, null, false],
            ['32', 'أرباح وخسائر مرحلة', AccountType::Equity, '3', false, 'retained_earnings', false],

            ['41', 'إيرادات المبيعات', AccountType::Revenue, '4', true, null, false],
            ['411', 'المبيعات', AccountType::Revenue, '41', false, 'sales_revenue', false],
            ['412', 'مردودات المبيعات', AccountType::Revenue, '41', false, 'sales_returns', true],
            ['42', 'إيرادات أخرى', AccountType::Revenue, '4', true, null, false],
            ['4201', 'إيرادات أخرى', AccountType::Revenue, '42', false, 'revenues_default', false],
            ['4202', 'أرباح وخسائر رأسمالية', AccountType::Revenue, '42', false, 'asset_gain', false],
            ['4203', 'خصم مكتسب', AccountType::Revenue, '42', false, 'purchase_discount', false],
            ['4204', 'تسويات المبيعات', AccountType::Revenue, '42', false, 'sales_adjustment', false],
            ['4208', 'إيرادات الشحن', AccountType::Revenue, '42', false, 'sales_shipping', false],
            ['4209', 'أرباح فروق العملة', AccountType::Revenue, '42', false, 'fx_gain', false],
            ['4213', 'فروق تقريب ضريبية', AccountType::Revenue, '42', false, 'tax_rounding', false],

            ['51', 'تكلفة المبيعات', AccountType::Expense, '5', true, null, false],
            ['511', 'تكلفة المبيعات', AccountType::Expense, '51', false, 'cogs', false],
            ['512', 'شحن مشتريات', AccountType::Expense, '51', false, null, false],
            ['513', 'خصم مسموح به', AccountType::Expense, '51', false, 'sales_discount', false],
            ['52', 'مصروفات إدارية وعمومية', AccountType::Expense, '5', true, null, false],
            ['5201', 'إيجار', AccountType::Expense, '52', false, null, false],
            ['5202', 'كهرباء', AccountType::Expense, '52', false, null, false],
            ['5203', 'هاتف وانترنت', AccountType::Expense, '52', false, null, false],
            ['5204', 'صيانة', AccountType::Expense, '52', false, null, false],
            ['5205', 'مياه', AccountType::Expense, '52', false, null, false],
            ['5206', 'مصاريف حكومية', AccountType::Expense, '52', false, null, false],
            ['53', 'مصروفات الإهلاك', AccountType::Expense, '5', true, null, false],
            ['5301', 'مصروف الإهلاك', AccountType::Expense, '53', false, 'depreciation_expense', false],
            ['54', 'مصروفات أخرى', AccountType::Expense, '5', true, null, false],
            ['541', 'مصروفات أخرى', AccountType::Expense, '54', false, 'expenses_default', false],
            ['542', 'الديون المعدومة', AccountType::Expense, '54', false, null, false],
            ['543', 'عجز وزيادة المخزون', AccountType::Expense, '54', false, null, false],
            ['544', 'إعادة تقييم', AccountType::Expense, '54', false, null, false],
            ['545', 'إيجار', AccountType::Expense, '54', false, null, false],
            ['546', 'رسوم الإيداع', AccountType::Expense, '54', false, 'cheque_deposit_fees', false],
            ['5410', 'خسائر فروق العملة', AccountType::Expense, '54', false, 'fx_loss', false],
            ['5420', 'خسائر بيع وشطب الأصول', AccountType::Expense, '54', false, 'asset_loss', false],
        ];
    }
}
