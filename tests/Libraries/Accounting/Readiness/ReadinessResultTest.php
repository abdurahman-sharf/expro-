<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\ReadinessResult;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

class ReadinessResultTest extends CIUnitTestCase
{
    /**
     * @param list<CheckResult> $checks
     * @param list<array{version: string, class: string, kind: string, note: string}> $pending
     */
    private function readiness(array $checks, array $pending = []): ReadinessResult
    {
        return new ReadinessResult($checks, $pending, true, 'owner_db', '2026-10-30 10:00:00');
    }

    private function named(CheckResult $check, string $id = 'check'): CheckResult
    {
        return $check->named($id, 'REF', 'Title');
    }

    public function testReady_IsTrueWhenNothingFailedAndNothingBroke(): void
    {
        $result = $this->readiness([
            $this->named(CheckResult::pass('ok')),
            $this->named(CheckResult::review('look')),
            $this->named(CheckResult::skipped('later')),
        ]);

        $this->assertTrue($result->ready());
        $this->assertSame([], $result->blocking());
    }

    public function testReady_IsFalseWhenOneCheckFailed(): void
    {
        $failed = $this->named(CheckResult::fail('bad'), 'failed');
        $result = $this->readiness([$this->named(CheckResult::pass('ok')), $failed]);

        $this->assertFalse($result->ready());
        $this->assertSame([$failed], $result->blocking());
    }

    public function testReady_IsFalseWhenOneCheckBroke(): void
    {
        $broke = $this->named(CheckResult::error(new RuntimeException('x')), 'broke');

        $this->assertFalse($this->readiness([$broke])->ready());
        $this->assertSame([$broke], $this->readiness([$broke])->blocking());
    }

    public function testReady_IsTrueWithNoCheckAtAll(): void
    {
        $this->assertTrue($this->readiness([])->ready());
    }

    public function testPhase_IsPreMigrationWhileAMigrationIsPending(): void
    {
        $pending = [['version' => '20270200000001', 'class' => 'CrossBranchPayments', 'kind' => 'heavy', 'note' => '']];

        $this->assertSame('pre-migration', $this->readiness([], $pending)->phase());
    }

    public function testPhase_IsPostMigrationWhenNothingIsPending(): void
    {
        $this->assertSame('post-migration', $this->readiness([])->phase());
    }

    public function testCounts_CountsEveryStatusIncludingTheOnesWithNoCheck(): void
    {
        $result = $this->readiness([
            $this->named(CheckResult::pass('a')),
            $this->named(CheckResult::pass('b')),
            $this->named(CheckResult::fail('c')),
            $this->named(CheckResult::review('d')),
        ]);

        $this->assertSame(['pass' => 2, 'fail' => 1, 'review' => 1, 'skipped' => 0, 'error' => 0], $result->counts());
    }

    public function testApplicable_ListsTheCommandOfAFailedCheckOnce(): void
    {
        $stamp = ['command' => 'accounting:stamp_payments', 'params' => ['apply' => null]];
        $move  = ['command' => 'accounting:bank_accounts', 'params' => ['move' => null]];

        $result = $this->readiness([
            $this->named(CheckResult::fail('a', [], [], $stamp), 'one'),
            $this->named(CheckResult::fail('b', [], [], $stamp), 'two'),
            $this->named(CheckResult::fail('c', [], [], $move), 'three'),
        ]);

        $this->assertSame([$stamp, $move], $result->applicable());
    }

    public function testApplicable_LeavesOutACheckThatDidNotFailAndAFailureNoCommandClears(): void
    {
        $stamp = ['command' => 'accounting:stamp_payments', 'params' => ['apply' => null]];

        $result = $this->readiness([
            $this->named(CheckResult::review('a')),
            $this->named(CheckResult::fail('no command')),
            new CheckResult(CheckStatus::Pass, 'passed with a command', apply: $stamp),
        ]);

        $this->assertSame([], $result->applicable());
    }

    public function testToArray_GivesTheExitCodeTheFlagsAndEveryCheck(): void
    {
        $result = $this->readiness([$this->named(CheckResult::fail('bad'))]);
        $array  = $result->toArray();

        $this->assertFalse($array['ready']);
        $this->assertSame(1, $array['exit_code']);
        $this->assertTrue($array['read_only']);
        $this->assertSame('post-migration', $array['phase']);
        $this->assertSame('owner_db', $array['database']);
        $this->assertSame('2026-10-30 10:00:00', $array['generated_at']);
        $this->assertSame(1, $array['counts']['fail']);
        $this->assertSame('fail', $array['checks'][0]['status']);
    }

    public function testToArray_GivesExitCodeZeroWhenReady(): void
    {
        $array = $this->readiness([$this->named(CheckResult::pass('ok'))])->toArray();

        $this->assertTrue($array['ready']);
        $this->assertSame(0, $array['exit_code']);
    }
}
