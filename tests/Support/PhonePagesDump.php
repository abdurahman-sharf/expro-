<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/**
 * Writes the widest accounting screens, in English and in Arabic, as static pages that load the style sheets
 * of public/assets from disk, for tools/ui-smoke/phone-pass.mjs to open in a browser at the width of a phone.
 * It is not part of the suite (its name does not end in Test): run it by its path, and give the folder in
 * PHONE_PAGES_DIR.
 *
 *     PHONE_PAGES_DIR=/tmp/pages MYSQL_DB_NAME=ospos_test_x vendor/bin/phpunit --no-coverage tests/Support/PhonePagesDump.php
 */
class PhonePagesDump extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use ReportFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();
        $this->stopSelling();

        parent::tearDown();
    }

    public function testDump_WritesEveryScreenInBothLanguagesWithAnOkStatus(): void
    {
        $directory = rtrim((string) (getenv('PHONE_PAGES_DIR') ?: sys_get_temp_dir() . '/phone-pages'), '/');

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $second = $this->createBranch('B2', 'فرع الرياض الثاني للتجزئة والجملة');

        $this->invoiceDated('1250.50', $this->daysAgo(40));
        $this->invoiceDated('300.00', $this->daysAgo(12));
        $this->invoiceDated('99.75', $this->daysAgo(2), null, $this->anotherCustomer('شركة الخليج العربي للتجارة والمقاولات المحدودة', 'ذ.م.م'));
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->cashSale('2026-10-05', $second, '2222.50');
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('sales_revenue'))->update(['name' => 'إيرادات المبيعات النقدية والآجلة من جميع الفروع والمنافذ - Sales revenue of all outlets and branches']);

        $person = $this->employeeWithGrants([
            'accounting_reports', 'accounting_receivables', 'accounting_payables', 'accounting_cheques', 'accounting_treasuries', 'acc_bank_reconcile',
            'accounting_periods', 'acc_year_end_close', 'accounting_journals', 'accounting_chart', 'reports_sales', 'reports_items', 'reports_customers',
            'reports_receivings', 'reports_suppliers', 'reports_inventory', 'reports_payments', 'reports_employees', 'reports_categories',
        ]);
        $cash  = $this->accountId('cash');
        $pages = [
            'trial'      => '/accounting_reports?type=balances&to=2026-10-31',
            'trial_mov'  => '/accounting_reports?type=review_totals&from=2026-10-01&to=2026-10-31',
            'ledger'     => '/accounting_reports/ledger?account=' . $cash . '&to=2026-10-31',
            'ledger_blk' => '/accounting_reports/ledger?layout=accounts&account=' . $cash . '&to=2026-10-31',
            'income'     => '/accounting_statements/income_statement?to=2026-10-31&level=3',
            'balance'    => '/accounting_statements/balance_sheet?to=2026-10-31&level=3',
            'aged'       => '/accounting_receivables/aged_ledger',
            'balances'   => '/accounting_receivables/balances',
            'revenue'    => '/accounting_reports/revenue?show=1&group=day&from=2026-01-01&to=2026-12-31',
            'chart'      => '/accounting_reports/chart',
            'invval'     => '/accounting_reports/inventory_valuation',
            'cheques'    => '/accounting_cheques/report/received',
            'interbr'    => '/accounting_treasuries/inter_branch',
            'yearend'    => '/accounting_periods/year_end',
            'hub'        => '/reports',
        ];

        foreach (['en' => ['english', 'en'], 'ar' => ['arabic-egypt', 'ar-EG']] as $key => [$language, $code]) {
            $this->accountingDb()->table('employees')->where('person_id', $person)->update(['language_code' => $code, 'language' => $language]);
            $this->withSession(['person_id' => $person, 'menu_group' => 'home']);

            foreach ($pages as $name => $url) {
                $response = $this->get($url);

                $response->assertStatus(200);

                file_put_contents($directory . '/' . $key . '-' . $name . '.html', str_replace(base_url(), 'file://' . rtrim(FCPATH, '/') . '/', $response->getBody()));
            }
        }
    }
}
