<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\Ai\ProposalText;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * @internal
 */
final class ProposalTextTest extends CIUnitTestCase
{
    private const ARGS = ['amount' => '250.0000', 'from' => '5900 Other expenses', 'to' => '5100 Rent'];

    protected function setUp(): void
    {
        parent::setUp();

        Services::language()->setLocale('en');
    }

    public function testFill_FillsTheTemplateOfTheKindWithTheFigures(): void
    {
        $this->assertSame('Move 250.0000 from 5900 Other expenses to 5100 Rent', ProposalText::fill('title', 'reclassify', self::ARGS));
    }

    public function testFill_TakesTheTemplateOfThePartAndNotOfTheKindAlone(): void
    {
        $title     = ProposalText::fill('title', 'reclassify', self::ARGS);
        $rationale = ProposalText::fill('rationale', 'reclassify', self::ARGS + ['text' => 'Rent', 'number' => '9', 'uses' => 3, 'total' => 3, 'share' => 100]);

        $this->assertNotSame($title, $rationale);
        $this->assertStringContainsString('Nothing is posted until a person approves', $rationale);
    }

    public function testFill_ShowsWhatWasStoredForAKindThatHasNoTemplate(): void
    {
        $this->assertSame('Stored words', ProposalText::fill('title', 'nosuchkind', [], 'Stored words'));
    }

    public function testFill_ShowsTheKindWhenNothingWasStored(): void
    {
        $this->assertSame('nosuchkind', ProposalText::fill('title', 'nosuchkind', []));
    }

    public function testFill_PrefersTheTemplateToWhatWasStored(): void
    {
        $this->assertSame('Move 250.0000 from 5900 Other expenses to 5100 Rent', ProposalText::fill('title', 'reclassify', self::ARGS, 'Old words'));
    }

    public function testArgsOf_ReadsTheFiguresThatWereStored(): void
    {
        $this->assertSame(['amount' => '250.0000', 'uses' => 3, 'flag' => true], ProposalText::argsOf('{"amount":"250.0000","uses":3,"flag":true}'));
    }

    public function testArgsOf_LeavesOutWhatIsNotTextOrANumber(): void
    {
        $this->assertSame(['amount' => '1'], ProposalText::argsOf('{"amount":"1","rows":[1,2],"nested":{"a":1},"none":null}'));
    }

    public function testArgsOf_GivesNothingForNothingOrForWhatIsNotAnObject(): void
    {
        $this->assertSame([], ProposalText::argsOf(null));
        $this->assertSame([], ProposalText::argsOf(''));
        $this->assertSame([], ProposalText::argsOf('not json'));
        $this->assertSame([], ProposalText::argsOf('5'));
        $this->assertSame([], ProposalText::argsOf('"words"'));
    }
}
