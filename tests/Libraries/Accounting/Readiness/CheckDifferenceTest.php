<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckDifference;
use CodeIgniter\Test\CIUnitTestCase;

final class CheckDifferenceTest extends CIUnitTestCase
{
    public function testFlagged_IsTrueForAChangedValueOrAProblemAndFalseForWhatIsExpectedOrNotComparable(): void
    {
        $this->assertTrue((new CheckDifference('a', 'A', 'a', 'pass', 'pass', changes: [['path' => 'data.x', 'before' => 1, 'after' => 2]]))->flagged());
        $this->assertTrue((new CheckDifference('a', 'A', 'a', 'pass', 'fail', problems: ['now FAIL']))->flagged());
        $this->assertFalse((new CheckDifference('a', 'A', 'a', 'review', 'pass', expected: [['path' => 'data.pending', 'before' => 1, 'after' => 0, 'why' => 'migrations']]))->flagged());
        $this->assertFalse((new CheckDifference('a', 'A', 'a', 'skipped', 'pass', notComparable: 'skipped before'))->flagged());
    }

    public function testListed_IsFalseOnlyForAnUnchangedCheck(): void
    {
        $this->assertFalse((new CheckDifference('a', 'A', 'a', 'pass', 'pass'))->listed());
        $this->assertTrue((new CheckDifference('a', 'A', 'a', 'pass', 'review'))->listed());
        $this->assertTrue((new CheckDifference('a', 'A', 'a', 'pass', 'pass', notComparable: 'x'))->listed());
        $this->assertTrue((new CheckDifference('a', 'A', 'a', 'pass', 'pass', expected: [['path' => 'p', 'before' => 1, 'after' => 2, 'why' => 'w']]))->listed());
    }

    public function testToArray_GivesEveryFieldUnderItsName(): void
    {
        $array = (new CheckDifference('a', 'REF', 'Title', 'pass', 'fail', problems: ['p']))->toArray();

        $this->assertSame(['id', 'ref', 'title', 'flagged', 'status_before', 'status_after', 'changes', 'expected', 'problems', 'not_comparable'], array_keys($array));
        $this->assertTrue($array['flagged']);
        $this->assertNull($array['not_comparable']);
    }
}
