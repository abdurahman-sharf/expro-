<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RevenueDocument;
use App\Libraries\Accounting\RevenueFacts;
use App\Libraries\Accounting\RevenuePostingBuilder;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class RevenueFactsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $treasuryId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Voucher drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
    }

    private function revenue(string $amount = '115.00', string $tax = '15.00', string $payment = 'Cash', ?int $account = null, ?int $category = null, ?int $treasury = null): RevenueDocument
    {
        return new RevenueDocument(52, '2026-10-21', $amount, $tax, $payment, $account, $category, $treasury, 1, false);
    }

    /**
     * @return array{0: JournalDraft|string|null, 1: JournalDraft|string|null}
     */
    private function both(RevenueDocument $revenue): array
    {
        $outcome = static function (callable $build): JournalDraft|string|null {
            try {
                return $build();
            } catch (PostingException $failure) {
                return $failure->error->value;
            }
        };

        return [
            $outcome(static fn () => (new RevenuePostingBuilder(new RoutingResolver()))->revenue($revenue, 1, 1)),
            $outcome(static fn () => (new PostingEngine())->build(RevenueFacts::of($revenue, 1, 1))),
        ];
    }

    private function assertSameOutcome(RevenueDocument $revenue, string $label): void
    {
        [$legacy, $engine] = $this->both($revenue);

        if (! $legacy instanceof JournalDraft || ! $engine instanceof JournalDraft) {
            $this->assertSame($legacy instanceof JournalDraft ? 'a journal' : $legacy, $engine instanceof JournalDraft ? 'a journal' : $engine, $label);

            return;
        }

        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine), $label);
        $this->assertSame($legacy->description, $engine->description, $label);
    }

    public function testOf_GivesTheEngineWhatMakesTheJournalOfTheCodeForEveryKindOfVoucher(): void
    {
        $chosen   = $this->customAccount('42', '4299', 'Chosen revenue');
        $category = $this->customAccount('42', '4298', 'Category revenue');
        $count    = 0;

        foreach (['115.00', '0', '-115.00'] as $amount) {
            foreach (['0', '15.00', '115.00', '-1.00', '120.00'] as $tax) {
                foreach ([lang('Sales.cash'), lang('Sales.check'), lang('Sales.debit'), lang('Sales.bank_transfer'), lang('Sales.wallet'), lang('Sales.due'), lang('Sales.giftcard'), 'Barter'] as $payment) {
                    foreach ([[null, null], [$chosen, null], [null, $category], [$chosen, $category]] as [$account, $categoryId]) {
                        foreach ([null, $this->treasuryId] as $treasury) {
                            $this->assertSameOutcome($this->revenue($amount, $tax, $payment, $account, $categoryId, $treasury), "{$amount}/{$tax}/{$payment}/" . ($account ?? '-') . '/' . ($categoryId ?? '-') . '/' . ($treasury ?? '-'));
                            $count++;
                        }
                    }
                }
            }
        }

        $this->assertSame(960, $count);
    }

    public function testOf_GivesTheEngineWhatMakesTheJournalOfTheCodeWhenARoutingRowItNeedsCannotBeUsed(): void
    {
        foreach ([RoutingKey::TaxOutput, RoutingKey::RevenuesDefault, RoutingKey::Treasuries] as $key) {
            foreach (['cancelled', 'per_item'] as $type) {
                $this->reinstallChart();
                $this->reopenEntityAccounts(['treasury' => [$this->treasuryId]]);
                $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type, 'account_id' => null]);

                $this->assertSameOutcome($this->revenue(treasury: $key === RoutingKey::Treasuries ? $this->treasuryId : null), "{$key->value} {$type}");
            }
        }
    }
}
