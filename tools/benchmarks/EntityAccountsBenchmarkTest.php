<?php

namespace Tools\Benchmarks;

use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Models\Customer;
use Closure;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * Measures the sub-accounts of customers and suppliers with many entities. Not part of the
 * suite: run it by name, with the sizes in the environment.
 *
 *   BENCH_CUSTOMERS=10000 BENCH_SUPPLIERS=2000 php vendor/bin/phpunit --no-coverage --no-logging tools/benchmarks/EntityAccountsBenchmarkTest.php
 *
 * It fills the test database with customers and suppliers named "Perf ..." and removes them again.
 * The framework logs every event it triggers while it runs in debug mode, which is every
 * statement, and never lets go of the log. That is not the application's memory, so the
 * benchmark empties the log as it goes and the memory figures stay those of a production run.
 * With BENCH_PROFILE=1 it also prints, for the fill, the posting and the customer save, the
 * statements that took the most time, to show what grows with the number of accounts.
 */
class EntityAccountsBenchmarkTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private string $phase = '';

    /**
     * @var array<string, array{count: int, ms: float}>
     */
    private array $profile = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $this->removeBenchmarkEntities();
        $this->setConfigValue('accounting_enabled', '0');

        parent::tearDown();
    }

    public function testMeasure_SubAccountsWithManyCustomersAndSuppliers(): void
    {
        $customers = (int) (getenv('BENCH_CUSTOMERS') ?: 10000);
        $suppliers = (int) (getenv('BENCH_SUPPLIERS') ?: 2000);
        $db        = $this->accountingDb();
        $results   = [];

        Events::on('DBQuery', static function (): void {
            static $statements = 0;

            if (++$statements % 1000 === 0) {
                Closure::bind(static function (): void {
                    Events::$performanceLog = [];
                }, null, Events::class)();
            }
        });

        if (getenv('BENCH_PROFILE') === '1') {
            Events::on('DBQuery', function (Query $query): void {
                $shape = preg_replace('/\s+/', ' ', $query->getQuery()) ?? '';
                $shape = preg_replace(['/\'(?:[^\'\\\\]|\\\\.)*\'/', '/\b\d+\b/', '/\(\s*\?(?:\s*,\s*\?)*\s*\)/'], ['?', '?', '(?)'], $shape) ?? '';
                $key   = $this->phase . ' | ' . mb_substr($shape, 0, 160);

                $this->profile[$key] ??= ['count' => 0, 'ms' => 0.0];
                $this->profile[$key]['count']++;
                $this->profile[$key]['ms'] += $query->getDuration() * 1000;
            });
        }

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->setConfigValue('accounting_enabled', '0');

        $results['create customers and suppliers (plain rows, no accounts)'] = $this->time(fn () => $this->bulkInsert($customers, $suppliers));

        $entities                                                  = new EntityAccountService();
        $results['readiness check: count entities without account'] = $this->time(fn () => $entities->missingCounts());
        $results['AccountingSettings::readiness()']                 = $this->time(fn () => (new AccountingSettings())->readiness());

        $this->phase                                              = 'fill';
        $filled                                                   = 0;
        memory_reset_peak_usage();
        $results["fill: open {$customers} + {$suppliers} accounts"] = $this->time(function () use ($entities, &$filled): void {
            $filled = array_sum($entities->fill());
        });
        $results['fill: peak memory (MB)']                          = round(memory_get_peak_usage(true) / 1048576, 1);
        $results['fill: milliseconds per account']                  = round($results["fill: open {$customers} + {$suppliers} accounts"] / max(1, $filled), 3);
        $results['fill again (nothing to open)']                    = $this->time(fn () => $entities->fill());
        memory_reset_peak_usage();
        $results['readiness check after the fill']                  = $this->time(fn () => $entities->missingCounts());

        $service  = new AccountService();
        $parentId = (int) $this->accountId('receivables');

        $results['chart: open the customers group (children of 12041)'] = $this->time(fn () => count($service->children($parentId)));
        $results['chart: search by name']                              = $this->time(fn () => $service->search('Perf C' . intdiv($customers, 2)));
        $results['chart: AccountService::all() (account pickers)']      = $this->time(fn () => count($service->all()));
        $results['chart: AccountService::balances() with no journals']  = $this->time(fn () => count($service->balances()));
        $results['opening balances: allowed accounts']                 = $this->time(fn () => count((new OpeningBalanceService())->allowedAccounts()));

        $accountId = (int) $entities->accountFor(EntityAccountService::CUSTOMER, (int) $db->table('customers c')->join('people p', 'p.person_id = c.person_id')->like('p.first_name', 'Perf', 'after')->limit(1)->get()->getRow()->person_id);
        $journals  = new JournalService();
        $revenueId = $this->accountId('sales_revenue');
        $this->phase = 'post';
        $results['post 50 manual journals on customer accounts (total)'] = $this->time(function () use ($journals, $accountId, $revenueId): void {
            for ($i = 0; $i < 50; $i++) {
                $journals->postNew(['date' => '2026-10-21', 'lines' => [['account_id' => $accountId, 'debit' => '10.00'], ['account_id' => $revenueId, 'credit' => '10.00']]]);
            }
        });
        $results['chart: AccountService::balances() after 50 journals'] = $this->time(fn () => count($service->balances()));

        $this->setConfigValue('accounting_enabled', '1');
        $this->phase = 'save';
        $results['save 50 more customers through the model (total, accounts opened)'] = $this->time(function (): void {
            for ($i = 0; $i < 50; $i++) {
                $person   = ['first_name' => 'Perf', 'last_name' => "New{$i}", 'gender' => null, 'phone_number' => '', 'email' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => ''];
                $customer = ['company_name' => null, 'employee_id' => 1];
                model(Customer::class)->save_customer($person, $customer);
            }
        });

        $results['accounts in the chart']       = $db->table('accounting_accounts')->countAllResults();
        $results['peak memory after the fill, screens and postings (MB)'] = round(memory_get_peak_usage(true) / 1048576, 1);

        fwrite(STDERR, "\nBENCHMARK customers={$customers} suppliers={$suppliers}\n");

        foreach ($results as $label => $value) {
            fwrite(STDERR, sprintf("  %-70s %s\n", $label, is_float($value) || is_int($value) ? $value : json_encode($value)));
        }

        $this->printProfile();

        $this->assertSame(0, $entities->missingCounts() === [] ? 0 : 1);
    }

    private function printProfile(): void
    {
        if ($this->profile === []) {
            return;
        }

        uasort($this->profile, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        fwrite(STDERR, "\nPROFILE the statements that took the most time (phase | shape: runs, total ms, ms per run)\n");

        foreach (array_slice($this->profile, 0, 14, true) as $key => $row) {
            fwrite(STDERR, sprintf("  %9.1f ms  x%-6d %7.3f ms/run  %s\n", $row['ms'], $row['count'], $row['ms'] / $row['count'], $key));
        }
    }

    /**
     * @return float Milliseconds.
     */
    private function time(callable $work): float
    {
        $start = hrtime(true);
        $work();

        return round((hrtime(true) - $start) / 1e6, 1);
    }

    private function bulkInsert(int $customers, int $suppliers): void
    {
        $db = $this->accountingDb();

        foreach ([['C', $customers], ['S', $suppliers]] as [$tag, $count]) {
            for ($offset = 0; $offset < $count; $offset += 1000) {
                $rows = [];

                for ($i = $offset; $i < min($count, $offset + 1000); $i++) {
                    $number = $i % 20 === 0 ? 0 : $i;
                    $rows[] = ['first_name' => 'Perf', 'last_name' => "{$tag}{$number}", 'phone_number' => '', 'email' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => ''];
                }

                $db->table('people')->insertBatch($rows);
            }
        }

        foreach ([['customers', 'C'], ['suppliers', 'S']] as [$table, $tag]) {
            $ids = array_column($db->table('people')->select('person_id')->where('first_name', 'Perf')->like('last_name', $tag, 'after')->get()->getResultArray(), 'person_id');

            foreach (array_chunk($ids, 1000) as $chunk) {
                $db->table($table)->insertBatch(array_map(
                    static fn (int|string $id): array => $table === 'customers'
                        ? ['person_id' => $id, 'company_name' => null, 'employee_id' => 1]
                        : ['person_id' => $id, 'company_name' => "Perf Company {$id}", 'agency_name' => '', 'category' => 0],
                    $chunk,
                ));
            }
        }
    }

    private function removeBenchmarkEntities(): void
    {
        $db = $this->accountingDb();
        $this->releaseOpenTransactions();
        $this->clearJournals();

        $ids = array_column($db->table('people')->select('person_id')->where('first_name', 'Perf')->get()->getResultArray(), 'person_id');

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('accounting_accounts')->whereIn('entity_type', ['customer', 'supplier'])->delete();

        foreach (array_chunk($ids, 1000) as $chunk) {
            $db->table('customers')->whereIn('person_id', $chunk)->delete();
            $db->table('suppliers')->whereIn('person_id', $chunk)->delete();
            $db->table('people')->whereIn('person_id', $chunk)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('accounting_sequences')->like('series', 'sub:', 'after')->delete();
    }
}
