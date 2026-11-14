<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\ComparisonResult;
use App\Libraries\Accounting\Readiness\ReadinessComparison;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * The comparison of the report taken before the migrations with the one taken after: what it flags, what it
 * leaves as expected, and what it cannot compare. The reports are built by hand in the shape of
 * accounting:readiness --json.
 *
 * @internal
 */
final class ReadinessComparisonTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function check(string $id, string $status = 'pass', array $data = [], string $ref = ''): array
    {
        return ['id' => $id, 'ref' => $ref === '' ? strtoupper($id) : $ref, 'title' => "Title of {$id}", 'status' => $status, 'summary' => '', 'findings' => [], 'data' => $data, 'apply' => null];
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @param array<string, mixed>       $top
     *
     * @return array<string, mixed>
     */
    private function report(array $checks, array $top = []): array
    {
        return [...['database' => 'ospos', 'generated_at' => '2026-11-14 01:00:00', 'phase' => 'pre-migration', 'checks' => $checks], ...$top];
    }

    /**
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     */
    private function compared(array $before, array $after): ComparisonResult
    {
        return ReadinessComparison::compare($this->report($before), $this->report($after, ['generated_at' => '2026-11-14 02:00:00', 'phase' => 'post-migration']));
    }

    public function testDecode_AcceptsTheJsonOfTheReadinessReport(): void
    {
        $document = ReadinessComparison::decode((string) json_encode($this->report([$this->check('ledger_balance', 'pass', ['checked' => 3])])));

        $this->assertSame('ledger_balance', $document['checks'][0]['id']);
    }

    public function testDecode_RejectsTextThatIsNotJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('it is not valid JSON');

        ReadinessComparison::decode("Open Source Point of Sale\n{ }");
    }

    public function testDecode_RejectsADocumentWithNoChecks(): void
    {
        foreach (['[]', '{}', '{"checks": []}', '{"checks": "none"}', '3', 'null'] as $json) {
            try {
                ReadinessComparison::decode($json);
                $this->fail("{$json} was accepted.");
            } catch (InvalidArgumentException $refusal) {
                $this->assertStringContainsString('has no list of checks', $refusal->getMessage());
            }
        }
    }

    public function testDecode_RejectsACheckWithNoIdOrStatusOrWithDataThatIsNotAMap(): void
    {
        foreach ([[['status' => 'pass']], [['id' => 'x']], [['id' => 5, 'status' => 'pass']], [['id' => 'x', 'status' => 'pass', 'data' => 'text']], ['text']] as $checks) {
            try {
                ReadinessComparison::decode((string) json_encode(['checks' => $checks]));
                $this->fail('A malformed check was accepted.');
            } catch (InvalidArgumentException $refusal) {
                $this->assertStringContainsString('no id, no status or no data', $refusal->getMessage());
            }
        }
    }

    public function testCompare_FlagsAChangedValueOfTheData(): void
    {
        $result = $this->compared([$this->check('treasury_ties', 'pass', ['checked' => 3, 'differences' => 0])], [$this->check('treasury_ties', 'pass', ['checked' => 4, 'differences' => 0])]);

        $this->assertTrue($result->flagged());
        $this->assertSame([['path' => 'data.checked', 'before' => 3, 'after' => 4]], $result->flaggedChecks()[0]->changes);
    }

    public function testCompare_FlagsTheAgingTotalOfOneCustomerThatChanged(): void
    {
        $before = $this->check('customer_ties', 'pass', ['aging_totals' => [12 => '100.0000', 13 => '40.0000'], 'aging_total' => '140.0000']);
        $after  = $this->check('customer_ties', 'pass', ['aging_totals' => [12 => '100.0000', 13 => '45.0000'], 'aging_total' => '145.0000']);

        $result  = $this->compared([$before], [$after]);
        $changes = $result->flaggedChecks()[0]->changes;

        $this->assertSame(['data.aging_totals.13', 'data.aging_total'], array_column($changes, 'path'));
        $this->assertSame('40.0000', $changes[0]['before']);
        $this->assertSame('45.0000', $changes[0]['after']);
    }

    public function testCompare_FlagsAValueThatAppearsOrDisappears(): void
    {
        $before = $this->check('customer_ties', 'pass', ['aging_totals' => [12 => '100.0000', 13 => '40.0000']]);
        $after  = $this->check('customer_ties', 'pass', ['aging_totals' => [12 => '100.0000', 14 => '9.0000']]);

        $changes = $this->compared([$before], [$after])->flaggedChecks()[0]->changes;

        $this->assertContains(['path' => 'data.aging_totals.14', 'before' => ReadinessComparison::ABSENT, 'after' => '9.0000'], $changes);
        $this->assertContains(['path' => 'data.aging_totals.13', 'before' => '40.0000', 'after' => ReadinessComparison::ABSENT], $changes);
    }

    public function testCompare_AcceptsTheSameAmountWrittenWithAnotherScale(): void
    {
        $result = $this->compared([$this->check('customer_ties', 'pass', ['aging_total' => '100.00', 'checked' => 2])], [$this->check('customer_ties', 'pass', ['aging_total' => '100.0000', 'checked' => '2'])]);

        $this->assertFalse($result->flagged());
    }

    public function testCompare_FlagsADifferenceInTheLastDecimalOfAnAmount(): void
    {
        $result = $this->compared([$this->check('customer_ties', 'pass', ['aging_total' => '100.0000'])], [$this->check('customer_ties', 'pass', ['aging_total' => '100.0001'])]);

        $this->assertTrue($result->flagged());
    }

    public function testCompare_FlagsNothingWhenEveryValueIsTheSame(): void
    {
        $checks = [$this->check('a', 'pass', ['x' => [1, 2], 'y' => ['z' => null]]), $this->check('b', 'review', ['text' => 'same'])];

        $result = $this->compared($checks, $checks);

        $this->assertFalse($result->flagged());
        $this->assertSame([], $result->toArray()['differences']);
    }

    public function testCompare_LeavesWhatTheMigrationsChangeAsExpected(): void
    {
        $before = $this->check('migrations', 'review', ['files' => 117, 'pending' => [['version' => '1', 'class' => 'A', 'kind' => 'data', 'note' => '']], 'unknown' => []]);
        $after  = $this->check('migrations', 'pass', ['files' => 117, 'pending' => [], 'unknown' => []]);

        $result = $this->compared([$before], [$after]);
        $listed = $result->toArray()['differences'][0];

        $this->assertFalse($result->flagged());
        $this->assertNotSame([], $listed['expected']);
        $this->assertSame('the migrations run between the two reports', $listed['expected'][0]['why']);
        $this->assertSame('review', $listed['status_before']);
        $this->assertSame('pass', $listed['status_after']);
    }

    public function testCompare_FlagsACheckThatTurnedToFailOrError(): void
    {
        foreach (['fail', 'error'] as $status) {
            $result = $this->compared([$this->check('ledger_balance', 'pass', ['bad' => 0])], [$this->check('ledger_balance', $status, ['bad' => 0])]);

            $this->assertTrue($result->flagged(), "A check that turned to {$status} is flagged.");
            $this->assertStringContainsString('is ' . strtoupper($status) . ' in the second', $result->flaggedChecks()[0]->problems[0]);
        }
    }

    public function testCompare_DoesNotFlagACheckThatWasAlreadyFailingOrThatGotBetter(): void
    {
        $same     = $this->compared([$this->check('ledger_balance', 'fail', ['bad' => 1])], [$this->check('ledger_balance', 'fail', ['bad' => 1])]);
        $improved = $this->compared([$this->check('ledger_balance', 'fail', ['bad' => 1])], [$this->check('ledger_balance', 'pass', ['bad' => 1])]);
        $review   = $this->compared([$this->check('ledger_balance', 'pass', ['bad' => 0])], [$this->check('ledger_balance', 'review', ['bad' => 0])]);

        $this->assertFalse($same->flagged());
        $this->assertFalse($improved->flagged());
        $this->assertFalse($review->flagged());
        $this->assertSame('pass', $review->toArray()['differences'][0]['status_before']);
    }

    public function testCompare_CannotCompareACheckThatWasSkippedBeforeTheMigration(): void
    {
        $before = $this->check('customer_ties', 'skipped', ['missing' => ['customer_openings']]);
        $after  = $this->check('customer_ties', 'pass', ['checked' => 2, 'aging_totals' => [12 => '100.0000']]);

        $result = $this->compared([$before], [$after]);

        $this->assertFalse($result->flagged());
        $this->assertStringContainsString('skipped before', (string) $result->toArray()['differences'][0]['not_comparable']);
    }

    public function testCompare_FlagsACheckThatRanBeforeAndIsSkippedAfter(): void
    {
        $result = $this->compared([$this->check('customer_ties', 'pass', ['checked' => 2])], [$this->check('customer_ties', 'skipped', ['missing' => ['customer_openings']])]);

        $this->assertTrue($result->flagged());
        $this->assertStringContainsString('the check ran in the first report (pass) and is skipped in the second', $result->flaggedChecks()[0]->problems[0]);
    }

    public function testCompare_SaysACheckThatIsOnlyInOneOfTheReports(): void
    {
        $result = $this->compared([$this->check('a'), $this->check('gone')], [$this->check('a'), $this->check('added')]);
        $listed = array_column($result->toArray()['differences'], 'not_comparable', 'id');

        $this->assertFalse($result->flagged());
        $this->assertSame('the check is only in the second report', $listed['added']);
        $this->assertSame('the check is only in the first report', $listed['gone']);
    }

    public function testCompare_AcceptsThePurchaseLinesTheMigrationMarkedWhenTheyAreTheOnesTheReportNamed(): void
    {
        $before = $this->check('purchase_discount', 'pass', ['migration_applied' => false, 'lines' => 5, 'would_mark' => 3, 'would_mark_discount' => '30.00', 'would_keep' => 2, 'verdicts' => ['a' => 3]]);
        $after  = $this->check('purchase_discount', 'pass', ['migration_applied' => true, 'marked_lines' => 3, 'marked_discount' => '30.00']);

        $result = $this->compared([$before], [$after]);

        $this->assertFalse($result->flagged());
        $this->assertNotSame([], $result->toArray()['differences'][0]['expected']);
    }

    public function testCompare_FlagsPurchaseLinesTheMigrationMarkedDifferentlyFromWhatTheReportNamed(): void
    {
        $before = $this->check('purchase_discount', 'pass', ['migration_applied' => false, 'would_mark' => 3, 'would_mark_discount' => '30.00']);

        $lines    = $this->compared([$before], [$this->check('purchase_discount', 'pass', ['migration_applied' => true, 'marked_lines' => 2, 'marked_discount' => '30.00'])]);
        $discount = $this->compared([$before], [$this->check('purchase_discount', 'pass', ['migration_applied' => true, 'marked_lines' => 3, 'marked_discount' => '31.00'])]);

        $this->assertTrue($lines->flagged());
        $this->assertStringContainsString('would_mark was 3 before the migration and marked_lines is 2 after it', $lines->flaggedChecks()[0]->problems[0]);
        $this->assertTrue($discount->flagged());
        $this->assertStringContainsString('marked_discount is "31.00"', $discount->flaggedChecks()[0]->problems[0]);
    }

    public function testCompare_ComparesThePurchaseDiscountPlainlyWhenTheMigrationHadRunInBothReports(): void
    {
        $before = $this->check('purchase_discount', 'pass', ['migration_applied' => true, 'marked_lines' => 3, 'marked_discount' => '30.00']);
        $after  = $this->check('purchase_discount', 'pass', ['migration_applied' => true, 'marked_lines' => 4, 'marked_discount' => '30.00']);

        $result = $this->compared([$before], [$after]);

        $this->assertTrue($result->flagged());
        $this->assertSame('data.marked_lines', $result->flaggedChecks()[0]->changes[0]['path']);
    }

    public function testCompare_NotesTwoDifferentDatabasesAndAFirstReportTakenAfterTheMigrations(): void
    {
        $first  = $this->report([$this->check('a')], ['database' => 'copy', 'phase' => 'post-migration']);
        $second = $this->report([$this->check('a')], ['database' => 'live', 'phase' => 'post-migration', 'generated_at' => '2026-11-14 03:00:00']);

        $result = ReadinessComparison::compare($first, $second);
        $notes  = implode("\n", $result->notes);

        $this->assertStringContainsString('different databases (copy and live)', $notes);
        $this->assertStringContainsString('The first report says post-migration', $notes);
        $this->assertSame([], $result->blockers);
        $this->assertFalse($result->flagged(), 'A rehearsal on a copy against the live database is a note and not a blocker.');
    }

    public function testCompare_BlocksASecondReportThatStillSaysPreMigrationWhateverTheChecksSay(): void
    {
        $first  = $this->report([$this->check('a', 'pass', ['x' => 1])]);
        $second = $this->report([$this->check('a', 'pass', ['x' => 1])], ['generated_at' => '2026-11-14 03:00:00', 'phase' => 'pre-migration']);

        $result = ReadinessComparison::compare($first, $second);

        $this->assertSame([], $result->flaggedChecks());
        $this->assertTrue($result->flagged());
        $this->assertCount(1, $result->blockers);
        $this->assertStringContainsString('The second report still says pre-migration', $result->blockers[0]);
        $this->assertSame($result->blockers, $result->toArray()['blockers']);
    }

    public function testCompare_BlocksTwoFilesThatAreOneReport(): void
    {
        $report = $this->report([$this->check('a')], ['phase' => 'post-migration']);

        $result = ReadinessComparison::compare($report, $report);

        $this->assertTrue($result->flagged());
        $this->assertStringContainsString('they are one report', implode("\n", $result->blockers));
    }

    public function testCompare_DoesNotBlockTwoReportsOfTheSameDatabaseTakenAtDifferentMoments(): void
    {
        $first  = $this->report([$this->check('a')], ['phase' => 'post-migration']);
        $second = $this->report([$this->check('a')], ['phase' => 'post-migration', 'generated_at' => '2026-11-14 01:00:01']);

        $this->assertSame([], ReadinessComparison::compare($first, $second)->blockers);
    }

    public function testCompare_DoesNotBlockTheSameMomentOfTwoDifferentDatabases(): void
    {
        $first  = $this->report([$this->check('a')], ['phase' => 'post-migration', 'database' => 'copy']);
        $second = $this->report([$this->check('a')], ['phase' => 'post-migration', 'database' => 'live']);

        $this->assertSame([], ReadinessComparison::compare($first, $second)->blockers);
    }

    public function testCompare_FlagsARecordedMigrationThatNoFileNamesEvenInTheCheckOfTheMigrations(): void
    {
        $before = $this->check('migrations', 'review', ['files' => 117, 'pending' => [['version' => '1']], 'unknown' => []]);
        $after  = $this->check('migrations', 'review', ['files' => 117, 'pending' => [], 'unknown' => ['20990101000000']]);

        $result  = $this->compared([$before], [$after]);
        $flagged = $result->flaggedChecks();

        $this->assertCount(1, $flagged);
        $this->assertSame('data.unknown.0', $flagged[0]->changes[0]['path']);
        $this->assertSame(ReadinessComparison::ABSENT, $flagged[0]->changes[0]['before']);
        $this->assertSame('20990101000000', $flagged[0]->changes[0]['after']);
        $this->assertContains('data.pending.0.version', array_column($flagged[0]->expected, 'path'), 'The pending list stays expected.');
        $this->assertNotContains('data.unknown.0', array_column($flagged[0]->expected, 'path'));
    }

    public function testCompare_ListsEveryCheckOfEitherReportInTheOrderOfTheSecond(): void
    {
        $result = $this->compared([$this->check('b'), $this->check('a'), $this->check('gone')], [$this->check('a'), $this->check('b'), $this->check('added')]);

        $this->assertSame(['a', 'b', 'added', 'gone'], array_map(static fn ($difference): string => $difference->id, $result->differences));
    }
}
