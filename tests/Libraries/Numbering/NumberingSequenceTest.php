<?php

namespace Tests\Libraries\Numbering;

use App\Libraries\Numbering\DocumentType;
use App\Libraries\Numbering\NumberingSequence;
use CodeIgniter\Test\CIUnitTestCase;

class NumberingSequenceTest extends CIUnitTestCase
{
    public function testFormat_StartsWithThePrefixAndPadsTheNumberWithZeros(): void
    {
        $sequence = new NumberingSequence(DocumentType::CreditNote, true, 'CN-', 5, 1, true);

        $this->assertSame('CN-00042', $sequence->format(42));
    }

    public function testFormat_GivesTheBareNumberWhenThereAreNoDigits(): void
    {
        $sequence = new NumberingSequence(DocumentType::CreditNote, true, '', 0, 1, true);

        $this->assertSame('42', $sequence->format(42));
    }

    public function testFormat_DoesNotCutANumberLongerThanTheDigits(): void
    {
        $sequence = new NumberingSequence(DocumentType::CreditNote, true, 'CN-', 3, 1, true);

        $this->assertSame('CN-12345', $sequence->format(12345));
    }

    public function testPreview_FormatsTheNextNumber(): void
    {
        $sequence = new NumberingSequence(DocumentType::CreditNote, true, 'CN-', 4, 7, true);

        $this->assertSame('CN-0007', $sequence->preview());
    }

    public function testDefaultFor_IsSwitchedOffWithTheShapeOfToday(): void
    {
        $sequence = NumberingSequence::defaultFor(DocumentType::Expense, 9);

        $this->assertFalse($sequence->enabled);
        $this->assertSame('', $sequence->prefix);
        $this->assertSame(0, $sequence->padding);
        $this->assertSame(9, $sequence->nextNumber);
        $this->assertTrue($sequence->preventDuplicates);
    }
}
