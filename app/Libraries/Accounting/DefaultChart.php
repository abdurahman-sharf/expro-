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
            $this->db->table('accounting_routing')->ignore(true)->insert([
                'routing_key' => $routingKey->value,
                'account_id'  => $accountIdsByKey[$routingKey->defaultSystemKey()] ?? null,
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
     * Rows are [code, name, type, parent code, is group, system key, contra account].
     *
     * @return list<array{string, string, AccountType, ?string, bool, ?string, bool}>
     */
    private static function accounts(): array
    {
        return [
            ['1', 'الأصول', AccountType::Asset, null, true, null, false],
            ['2', 'الخصوم', AccountType::Liability, null, true, null, false],
            ['3', 'حقوق الملكية', AccountType::Equity, null, true, null, false],
            ['4', 'الإيرادات', AccountType::Revenue, null, true, null, false],
            ['5', 'المصروفات', AccountType::Expense, null, true, null, false],

            ['11', 'الأصول المتداولة', AccountType::Asset, '1', true, null, false],
            ['1101', 'الصندوق', AccountType::Asset, '11', false, 'cash', false],
            ['1102', 'البنوك', AccountType::Asset, '11', false, null, false],
            ['1103', 'ذمم العملاء المدينة', AccountType::Asset, '11', false, 'receivables', false],
            ['1104', 'المخزون', AccountType::Asset, '11', false, 'inventory', false],
            ['1105', 'ضريبة القيمة المضافة (مدخلات)', AccountType::Asset, '11', false, 'tax_input', false],

            ['12', 'الأصول الثابتة', AccountType::Asset, '1', true, null, false],
            ['1201', 'الأصول الثابتة', AccountType::Asset, '12', false, null, false],
            ['1202', 'مجمع الإهلاك', AccountType::Asset, '12', false, null, true],

            ['21', 'الخصوم المتداولة', AccountType::Liability, '2', true, null, false],
            ['2101', 'ذمم الموردين الدائنة', AccountType::Liability, '21', false, 'payables', false],
            ['2102', 'ضريبة القيمة المضافة (مخرجات)', AccountType::Liability, '21', false, 'tax_output', false],
            ['2103', 'بطاقات الهدايا غير المستخدمة', AccountType::Liability, '21', false, 'giftcard_liability', false],
            ['2104', 'مصروفات مستحقة', AccountType::Liability, '21', false, null, false],

            ['31', 'حقوق الملاك', AccountType::Equity, '3', true, null, false],
            ['3101', 'رأس المال', AccountType::Equity, '31', false, null, false],
            ['3102', 'الأرباح المحتجزة', AccountType::Equity, '31', false, 'retained_earnings', false],
            ['3103', 'الأرصدة الافتتاحية', AccountType::Equity, '31', false, 'opening_balance', false],

            ['41', 'إيرادات النشاط', AccountType::Revenue, '4', true, null, false],
            ['4101', 'المبيعات', AccountType::Revenue, '41', false, 'sales_revenue', false],
            ['4102', 'مردودات المبيعات', AccountType::Revenue, '41', false, 'sales_returns', true],
            ['4103', 'خصم مسموح به', AccountType::Revenue, '41', false, 'sales_discount', true],
            ['42', 'إيرادات أخرى', AccountType::Revenue, '4', true, null, false],
            ['4201', 'إيرادات متنوعة', AccountType::Revenue, '42', false, null, false],

            ['51', 'تكلفة المبيعات', AccountType::Expense, '5', true, null, false],
            ['5101', 'تكلفة البضاعة المباعة', AccountType::Expense, '51', false, 'cogs', false],
            ['52', 'مصروفات التشغيل', AccountType::Expense, '5', true, null, false],
            ['5201', 'مصروفات عمومية وإدارية', AccountType::Expense, '52', false, 'expenses_default', false],
            ['5202', 'فروق الصندوق', AccountType::Expense, '52', false, 'cash_difference', false],
        ];
    }
}
