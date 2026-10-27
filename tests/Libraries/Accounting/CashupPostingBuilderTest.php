<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\CashupDocument;
use App\Libraries\Accounting\CashupPostingBuilder;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ErrorException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The journal a saved cash-up needs, as a draft: only the difference between the cash that was
 * counted and the cash that was expected, a shortage against the cash difference account and an
 * overage the other way round.
 */
class CashupPostingBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CashupPostingBuilder $builder;
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

        $this->builder    = new CashupPostingBuilder(new RoutingResolver());
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Cash-up drawer', 'kind' => 'cash']);
    }

    protected function tearDown(): void
    {
        $this->clearJournals();

        parent::tearDown();
    }

    private function cashup(?string $difference, ?int $treasuryId = -1): CashupDocument
    {
        return new CashupDocument(cashupId: 31, date: '2026-10-20', difference: $difference, treasuryId: $treasuryId === -1 ? $this->treasuryId : $treasuryId, branchId: 1, deleted: false);
    }

    /**
     * @return list<string> "account:debit:credit" in line order.
     */
    private function linesOf(JournalDraft $draft): array
    {
        return array_map(static fn ($line): string => $line->accountId . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4), $draft->lines);
    }

    public function testDifference_DebitsTheCashDifferenceAndCreditsTheTreasuryForAShortage(): void
    {
        $draft = $this->builder->difference($this->cashup('-12.5000'), 1);

        $this->assertSame([$this->accountId('cash_difference') . ':12.5000:0.0000', (new TreasuryService())->accountOf($this->treasuryId) . ':0.0000:12.5000'], $this->linesOf($draft));
        $this->assertSame('Cash-up #31: cash short', $draft->description);
    }

    public function testDifference_DebitsTheTreasuryAndCreditsTheCashDifferenceForAnOverage(): void
    {
        $draft = $this->builder->difference($this->cashup('7.2500'), 1);

        $this->assertSame([(new TreasuryService())->accountOf($this->treasuryId) . ':7.2500:0.0000', $this->accountId('cash_difference') . ':0.0000:7.2500'], $this->linesOf($draft));
        $this->assertSame('Cash-up #31: cash over', $draft->description);
    }

    public function testDifference_PostsNothingWhenTheCountMatchesWhatWasExpected(): void
    {
        $this->assertNull($this->builder->difference($this->cashup('0.0000'), 1));
    }

    public function testDifference_PostsNothingWhileNothingHasBeenCountedAndDoesNoArithmeticOnIt(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $this->assertNull($this->builder->difference($this->cashup(null), 1));
        } finally {
            restore_error_handler();
        }
    }

    public function testDifference_UsesTheAccountCashIsRoutedToWithoutATreasury(): void
    {
        $draft = $this->builder->difference($this->cashup('-5.0000', null), 1);

        $this->assertSame($this->accountId('cash'), $draft->lines[1]->accountId);
    }

    public function testDifference_CarriesTheSourceTheDateTheBranchAndTheRevisionOfTheCashUp(): void
    {
        $draft = $this->builder->difference($this->cashup('-5.0000'), 3);

        $this->assertSame([CashupPostingBuilder::SOURCE, 31, 3, '2026-10-20', 1], [$draft->sourceType, $draft->sourceId, $draft->revision, $draft->date, $draft->branchId]);
    }

    public function testDifference_KeepsFourDecimalsWithoutDrift(): void
    {
        $draft = $this->builder->difference($this->cashup('-0.0100'), 1);

        $this->assertSame('0.0100', bcadd($draft->lines[0]->debit, '0', 4));
        $this->assertSame('0.0100', bcadd($draft->lines[1]->credit, '0', 4));
    }
}
