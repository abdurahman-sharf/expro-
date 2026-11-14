<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\Backfill\BackfillBudget;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class BackfillBudgetTest extends CIUnitTestCase
{
    public function testExhausted_NeverRunsOutWhenItSetsNeitherChunksNorSeconds(): void
    {
        $budget = new BackfillBudget();

        for ($chunk = 0; $chunk < 50; $chunk++) {
            $budget->spend();
        }

        $this->assertFalse($budget->exhausted());
    }

    public function testExhausted_RunsOutAfterTheChunksItWasGiven(): void
    {
        $budget = new BackfillBudget(2);

        $this->assertFalse($budget->exhausted());

        $budget->spend();

        $this->assertFalse($budget->exhausted());

        $budget->spend();

        $this->assertTrue($budget->exhausted());
    }

    public function testExhausted_RunsOutAtOnceWhenTheSecondsAreNone(): void
    {
        $this->assertTrue((new BackfillBudget(100, 0.0))->exhausted());
    }

    public function testExhausted_StopsBeforeAChunkThatWouldEndAfterTheSecondsGoingByTheSlowestChunkSoFar(): void
    {
        $budget = new BackfillBudget(null, 0.5);

        usleep(300000);
        $budget->spend();

        $this->assertTrue($budget->exhausted(), 'Another chunk of 0.3 seconds would end after the 0.5 seconds there are.');

        $roomy = new BackfillBudget(null, 30.0);

        usleep(20000);
        $roomy->spend();

        $this->assertFalse($roomy->exhausted());
    }
}
