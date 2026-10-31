<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

class CheckResultTest extends CIUnitTestCase
{
    public function testPass_KeepsTheSummaryTheFindingsAndTheData(): void
    {
        $result = CheckResult::pass('All good.', ['a note'], ['count' => 0]);

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('All good.', $result->summary);
        $this->assertSame(['a note'], $result->findings);
        $this->assertSame(['count' => 0], $result->data);
        $this->assertNull($result->apply);
    }

    public function testFail_CarriesTheCommandThatClearsIt(): void
    {
        $result = CheckResult::fail('Broken.', ['one'], ['n' => 1], ['command' => 'accounting:stamp_payments', 'params' => ['apply' => null]]);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('accounting:stamp_payments --apply', $result->applyCommand());
    }

    public function testReview_IsNeitherAPassNorAFailure(): void
    {
        $result = CheckResult::review('Look at it.');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertFalse($result->status->blocks());
    }

    public function testSkipped_KeepsWhatWasMissing(): void
    {
        $result = CheckResult::skipped('Not checked.', ['missing' => ['treasuries']]);

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertSame(['missing' => ['treasuries']], $result->data);
        $this->assertSame([], $result->findings);
    }

    public function testError_NamesTheFailureAndItsMessage(): void
    {
        $result = CheckResult::error(new RuntimeException('no table'));

        $this->assertSame(CheckStatus::Error, $result->status);
        $this->assertSame('The check could not run: RuntimeException: no table', $result->summary);
        $this->assertTrue($result->status->blocks());
    }

    public function testNamed_AddsTheIdentityAndKeepsEverythingElse(): void
    {
        $result = CheckResult::fail('Broken.', ['one'], ['n' => 1], ['command' => 'a:b', 'params' => ['move' => null]])->named('bank_placement', 'E40-BANK-DETAILS', 'Banks');

        $this->assertSame('bank_placement', $result->id);
        $this->assertSame('E40-BANK-DETAILS', $result->ref);
        $this->assertSame('Banks', $result->title);
        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['one'], $result->findings);
        $this->assertSame(['n' => 1], $result->data);
        $this->assertSame('a:b --move', $result->applyCommand());
    }

    public function testApplyCommand_IsNullWhenNothingClearsTheCheck(): void
    {
        $this->assertNull(CheckResult::fail('Broken.')->applyCommand());
    }

    public function testApplyCommand_WritesOneFlagForEachParameter(): void
    {
        $result = CheckResult::fail('Broken.', [], [], ['command' => 'a:b', 'params' => ['apply' => null, 'all' => null]]);

        $this->assertSame('a:b --apply --all', $result->applyCommand());
    }

    public function testToArray_GivesTheStatusAsAWordAndTheApplyCommandAsText(): void
    {
        $array = CheckResult::fail('Broken.', ['one'], ['n' => 1], ['command' => 'a:b', 'params' => ['apply' => null]])->named('id', 'REF', 'Title')->toArray();

        $this->assertSame(['id' => 'id', 'ref' => 'REF', 'title' => 'Title', 'status' => 'fail', 'summary' => 'Broken.', 'findings' => ['one'], 'data' => ['n' => 1], 'apply' => 'a:b --apply'], $array);
    }

    public function testToArray_GivesNullWhenThereIsNoCommandToApply(): void
    {
        $this->assertNull(CheckResult::pass('Fine.')->toArray()['apply']);
    }
}
