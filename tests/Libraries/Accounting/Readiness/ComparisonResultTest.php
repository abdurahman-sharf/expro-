<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckDifference;
use App\Libraries\Accounting\Readiness\ComparisonResult;
use CodeIgniter\Test\CIUnitTestCase;

final class ComparisonResultTest extends CIUnitTestCase
{
    public function testFlagged_IsTrueOnlyWhenACheckIsFlagged(): void
    {
        $quiet   = new CheckDifference('a', 'A', 'a', 'pass', 'pass');
        $changed = new CheckDifference('b', 'B', 'b', 'pass', 'pass', changes: [['path' => 'data.x', 'before' => 1, 'after' => 2]]);

        $this->assertFalse((new ComparisonResult([$quiet], []))->flagged());
        $this->assertTrue((new ComparisonResult([$quiet, $changed], []))->flagged());
        $this->assertSame([$changed], (new ComparisonResult([$quiet, $changed], []))->flaggedChecks());
    }

    public function testToArray_CountsTheChecksAndListsOnlyTheOnesThatDiffer(): void
    {
        $quiet   = new CheckDifference('a', 'A', 'a', 'pass', 'pass');
        $changed = new CheckDifference('b', 'B', 'b', 'pass', 'pass', changes: [['path' => 'data.x', 'before' => 1, 'after' => 2]]);
        $skipped = new CheckDifference('c', 'C', 'c', 'skipped', 'pass', notComparable: 'skipped before');

        $array = (new ComparisonResult([$quiet, $changed, $skipped], ['a note']))->toArray();

        $this->assertTrue($array['flagged']);
        $this->assertSame(3, $array['checks']);
        $this->assertSame(1, $array['flagged_checks']);
        $this->assertSame(['a note'], $array['notes']);
        $this->assertSame(['b', 'c'], array_column($array['differences'], 'id'));
    }
}
