<?php

namespace Tests\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Recurring\RunRefusal;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * The name of a refusal and the words it is told in later: where it came from, its code and what it knew,
 * kept as text in the history of a schedule and turned back into the sentence of the language in use.
 */
class RunRefusalTest extends CIUnitTestCase
{
    public function testFrom_NamesTheSourceAndTheCodeOfTheBooksTheirPostingAndTheirBranch(): void
    {
        $accounting = RunRefusal::from(new AccountingException(AccountingError::PermissionDenied, 'no'));
        $posting    = RunRefusal::from(new PostingException(PostingError::PeriodClosed, 'closed', ['date' => '2026-01-31', 'until' => '2026-02-28']));
        $branch     = RunRefusal::from(new BranchException(BranchError::NotAllowed, 'outside', ['branch_id' => 4]));

        $this->assertSame([RunRefusal::SOURCE_ACCOUNTING, 'permission_denied'], [$accounting->source, $accounting->error]);
        $this->assertSame([RunRefusal::SOURCE_POSTING, 'period_closed'], [$posting->source, $posting->error]);
        $this->assertSame([RunRefusal::SOURCE_BRANCH, 'not_allowed'], [$branch->source, $branch->error]);
        $this->assertSame(['date' => '2026-01-31', 'until' => '2026-02-28'], $posting->context);
    }

    public function testFrom_NamesAnythingElseAsASaleThatCouldNotBeSaved(): void
    {
        $other = RunRefusal::from(new RuntimeException('boom'));

        $this->assertSame([RunRefusal::SOURCE_SALE, 'save_failed'], [$other->source, $other->error]);
        $this->assertSame([RunRefusal::SOURCE_SALE, 'insufficient_stock'], [RunRefusal::sale('insufficient_stock')->source, RunRefusal::sale('insufficient_stock')->error]);
    }

    public function testFrom_KeepsOnlyWhatIsAScalarOfTheContext(): void
    {
        $refusal = RunRefusal::from(new AccountingException(AccountingError::SalesOrderLocked, 'x', ['name' => 'Open', 'list' => [1, 2], 'object' => new RuntimeException('no'), 'count' => 3]));

        $this->assertSame(['name' => 'Open', 'count' => 3], $refusal->context);
    }

    public function testContextJson_IsNullWithNoContextAndNeverLongerThanTheColumn(): void
    {
        $this->assertNull(RunRefusal::sale('save_failed')->contextJson());

        $long = new RunRefusal(RunRefusal::SOURCE_ACCOUNTING, 'permission_denied', ['name' => str_repeat('x', 900)]);

        $this->assertLessThanOrEqual(RunRefusal::CONTEXT_MAX, mb_strlen((string) $long->contextJson()));
    }

    public function testForDate_RemembersTheDateAndTheNumberOfTheOccurrence(): void
    {
        $refusal = RunRefusal::sale('save_failed')->forDate('2026-03-31', 2);

        $this->assertSame(['2026-03-31', 2], [$refusal->dueDate, $refusal->occurrence]);
    }

    public function testWords_TellsAPostingRefusalInTheSentenceOfItsMessageWithItsDates(): void
    {
        $refusal = RunRefusal::from(new PostingException(PostingError::PeriodClosed, 'closed', ['date' => '2026-01-31', 'until' => '2026-02-28']));

        $words = $refusal->words();

        $this->assertStringContainsString('2026-01-31', $words);
        $this->assertStringContainsString('2026-02-28', $words);
    }

    public function testWordsOf_RebuildsTheSentenceFromWhatWasStored(): void
    {
        $stored = RunRefusal::from(new AccountingException(AccountingError::RecurringDateInvalid, 'x', ['date' => '2026-05-05']));

        $this->assertSame($stored->words(), RunRefusal::wordsOf($stored->source, $stored->error, $stored->contextJson()));
        $this->assertStringContainsString('2026-05-05', RunRefusal::wordsOf('accounting', 'recurring_date_invalid', '{"date":"2026-05-05"}'));
    }

    public function testWordsOf_TellsTheSaleRefusalsOfTheSchedulesAndFallsBackToTheCode(): void
    {
        $this->assertSame(lang('Recurring.refusal_insufficient_stock'), RunRefusal::wordsOf('sale', 'insufficient_stock', null));
        $this->assertNotSame('', RunRefusal::wordsOf('sale', 'save_failed', null));
        $this->assertSame('something_new', RunRefusal::wordsOf('sale', 'something_new', null));
        $this->assertSame('gone_code', RunRefusal::wordsOf('accounting', 'gone_code', null));
        $this->assertSame('gone_code', RunRefusal::wordsOf(null, 'gone_code', 'not json'));
    }
}
