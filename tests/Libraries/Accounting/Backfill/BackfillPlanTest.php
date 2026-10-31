<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\Backfill\BackfillPlan;
use App\Libraries\Accounting\Backfill\BackfillRefusal;
use App\Libraries\Accounting\Backfill\BackfillStep;
use App\Libraries\Accounting\Backfill\BackfillStock;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\StockMovement;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BackfillPlanTest extends TestCase
{
    private function draft(string $amount = '100.00', int $debitAccount = 10, int $creditAccount = 20, string $date = '2026-03-10', int $branch = 1, string $description = 'Sale #1', ?int $employee = null, int $sourceId = 1): JournalDraft
    {
        return new JournalDraft(
            date: $date,
            lines: [new JournalLineDraft($debitAccount, debit: $amount), new JournalLineDraft($creditAccount, credit: $amount)],
            sourceType: 'sale',
            sourceId: $sourceId,
            employeeId: $employee,
            description: $description,
            branchId: $branch,
            entryType: JournalEntryType::Revenue,
        );
    }

    private function stock(string $quantity = '-2', string $value = '-80.00'): BackfillStock
    {
        return new BackfillStock('sale', 1, JournalEntryType::Cogs, 1, '2026-03-10', 1, [new StockMovement(5, 1, $quantity, $value)]);
    }

    /**
     * @param list<BackfillStep>   $steps
     * @param array<string, mixed> $stamp
     */
    private function plan(array $steps, string $source = '{"sale":1}', array $stamp = [], ?string $partyType = null, ?int $partyId = null): BackfillPlan
    {
        return new BackfillPlan($steps, $source, null, '', $partyType, $partyId, $stamp);
    }

    public function testDigest_IsTheSameForTheSameDocumentMadeTwice(): void
    {
        $this->assertSame($this->plan([new BackfillStep($this->draft(), $this->stock())])->digest(), $this->plan([new BackfillStep($this->draft(), $this->stock())])->digest());
    }

    public function testDigest_IgnoresWhoRanTheBatchAndHowAnAmountIsWritten(): void
    {
        $one = $this->plan([new BackfillStep($this->draft('100.00', employee: 7))]);
        $two = $this->plan([new BackfillStep($this->draft('100', employee: 9))]);

        $this->assertSame($one->digest(), $two->digest());
    }

    public function testDigest_ChangesWithAnAmountAnAccountTheDateTheBranchOrTheText(): void
    {
        $base = $this->plan([new BackfillStep($this->draft())])->digest();

        foreach ([
            $this->draft('100.01'),
            $this->draft(debitAccount: 11),
            $this->draft(creditAccount: 21),
            $this->draft(date: '2026-03-11'),
            $this->draft(branch: 2),
            $this->draft(description: 'Sale #2'),
            $this->draft(sourceId: 2),
        ] as $draft) {
            $this->assertNotSame($base, $this->plan([new BackfillStep($draft)])->digest());
        }
    }

    public function testDigest_ChangesWithTheStockMovementsTheStampTheDocumentAndTheParty(): void
    {
        $base = $this->plan([new BackfillStep($this->draft(), $this->stock())])->digest();

        $this->assertNotSame($base, $this->plan([new BackfillStep($this->draft(), $this->stock('-3', '-120.00'))])->digest());
        $this->assertNotSame($base, $this->plan([new BackfillStep($this->draft())])->digest());
        $this->assertNotSame($base, $this->plan([new BackfillStep($this->draft(), $this->stock())], stamp: ['columns' => ['branch_id' => 1]])->digest());
        $this->assertNotSame($base, $this->plan([new BackfillStep($this->draft(), $this->stock())], source: '{"sale":2}')->digest());
        $this->assertNotSame($base, $this->plan([new BackfillStep($this->draft(), $this->stock())], partyType: 'customer', partyId: 4)->digest());
        $this->assertNotSame($base, $this->plan([new BackfillStep($this->draft()), new BackfillStep($this->draft('5.00'))])->digest());
    }

    public function testDigest_SeparatesARefusalFromAReadyPlanAndOneReasonFromAnother(): void
    {
        $ready   = $this->plan([new BackfillStep($this->draft())]);
        $closed  = BackfillPlan::refused(BackfillRefusal::PeriodClosed, '{"sale":1}');
        $opening = BackfillPlan::refused(BackfillRefusal::BeforeOpening, '{"sale":1}');

        $this->assertNotSame($ready->digest(), $closed->digest());
        $this->assertNotSame($closed->digest(), $opening->digest());
        $this->assertNotSame($closed->digest(), BackfillPlan::refused(BackfillRefusal::PeriodClosed, '{"sale":1}', 'other detail')->digest());
    }

    public function testRefused_HasNoJournalsNoAmountAndIsNotReady(): void
    {
        $plan = BackfillPlan::refused(BackfillRefusal::ZeroTotal);

        $this->assertFalse($plan->isReady());
        $this->assertSame(0, $plan->journalCount());
        $this->assertSame('0.0000', $plan->total());
        $this->assertSame(BackfillRefusal::ZeroTotal, $plan->refusal);
    }

    public function testTotal_AddsTheDebitsOfEveryJournalAndJournalCountSkipsAStepWithOnlyStock(): void
    {
        $plan = $this->plan([new BackfillStep($this->draft('100.00')), new BackfillStep($this->draft('25.50')), new BackfillStep(null, $this->stock())]);

        $this->assertTrue($plan->isReady());
        $this->assertSame('125.5000', $plan->total());
        $this->assertSame(2, $plan->journalCount());
    }

    public function testOfPosting_NamesTheRulesOfTheBooksAndCallsTheRestTheBooksRefusing(): void
    {
        foreach ([
            [PostingError::PeriodClosed, BackfillRefusal::PeriodClosed],
            [PostingError::OutsideFiscalYear, BackfillRefusal::OutsideFiscalYear],
            [PostingError::BranchNotFound, BackfillRefusal::BranchUnknown],
            [PostingError::BranchInactive, BackfillRefusal::BranchInactive],
            [PostingError::ReturnOriginRequired, BackfillRefusal::ReturnOriginMissing],
            [PostingError::ReturnOriginInvalid, BackfillRefusal::ReturnOriginMissing],
            [PostingError::PurchaseReturnOriginRequired, BackfillRefusal::ReturnOriginMissing],
            [PostingError::PurchaseReturnOriginInvalid, BackfillRefusal::ReturnOriginMissing],
            [PostingError::CrossBranchPaymentInvalid, BackfillRefusal::CrossBranchPayment],
            [PostingError::PaymentTypeUnknown, BackfillRefusal::PostingRefused],
            [PostingError::Unbalanced, BackfillRefusal::PostingRefused],
        ] as [$error, $expected]) {
            $this->assertSame($expected, BackfillRefusal::ofPosting($error), $error->value);
        }
    }
}
