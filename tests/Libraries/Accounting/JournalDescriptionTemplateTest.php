<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalDescriptionTemplate;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class JournalDescriptionTemplateTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function validProvider(): array
    {
        return [
            'empty'                  => [''],
            'only blanks'            => ['   '],
            'plain words'            => ['Sales journal'],
            'every placeholder'      => ['{type} {number} for {party} on {date}'],
            'a placeholder twice'    => ['{number} / {number}'],
            'exactly the limit'      => [str_repeat('a', JournalDescriptionTemplate::MAX)],
            'arabic text'            => ['قيد {type} رقم {number} للعميل {party}'],
        ];
    }

    #[DataProvider('validProvider')]
    public function testIsValid_AcceptsPlainWordsAndTheFourPlaceholders(string $template): void
    {
        $this->assertTrue(JournalDescriptionTemplate::isValid($template));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'one over the limit'    => [str_repeat('a', JournalDescriptionTemplate::MAX + 1)],
            'unknown placeholder'   => ['{customer}'],
            'unclosed brace'        => ['Sale {number'],
            'stray closing brace'   => ['Sale number}'],
            'empty braces'          => ['{}'],
            'markup'                => ['<b>{number}</b>'],
            'angle bracket alone'   => ['a > b'],
            'a line break'          => ["a\nb"],
            'a control character'   => ["a\x07b"],
            'placeholder in braces' => ['{{number}}'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testIsValid_RejectsATemplateThatIsTooLongHasUnknownPlaceholdersMarkupOrControlCharacters(string $template): void
    {
        $this->assertFalse(JournalDescriptionTemplate::isValid($template));
    }

    public function testRender_ReplacesEachPlaceholderByItsValue(): void
    {
        $this->assertSame(
            'Invoice 000012 - Omar Khaled - 2026-10-21',
            JournalDescriptionTemplate::render('{type} {number} - {party} - {date}', ['type' => 'Invoice', 'number' => '000012', 'party' => 'Omar Khaled', 'date' => '2026-10-21']),
        );
    }

    public function testRender_GivesNothingForAnEmptyOrInvalidTemplateSoTheDefaultDescriptionStays(): void
    {
        $this->assertSame('', JournalDescriptionTemplate::render('', ['number' => '1']));
        $this->assertSame('', JournalDescriptionTemplate::render('  ', ['number' => '1']));
        $this->assertSame('', JournalDescriptionTemplate::render('{unknown}', ['number' => '1']));
    }

    public function testRender_CleansTheValuesOfMarkupAndControlCharacters(): void
    {
        $this->assertSame('Sale 1 2 for Omar Khaled', JournalDescriptionTemplate::render('Sale {number} for {party}', ['number' => "1\n2", 'party' => '<b>Omar</b>   Khaled']));
        $this->assertSame('x alert(1)', JournalDescriptionTemplate::render('x {party}', ['party' => '<script>alert(1)</script>']));
        $this->assertSame('Sale 1 2 for Omar', JournalDescriptionTemplate::render('Sale {number} for {party}', ['number' => "1\x072", 'party' => "Omar\x00"]), 'a bell and a null are not text');
    }

    public function testRender_LeavesAMissingValueEmptyAndTidiesTheSpaces(): void
    {
        $this->assertSame('Sale for', JournalDescriptionTemplate::render('Sale {number} for {party}', []));
    }

    public function testRender_CutsTheResultAtTheLengthOfALineDescription(): void
    {
        $text = JournalDescriptionTemplate::render('{party}', ['party' => str_repeat('م', 400)]);

        $this->assertSame(JournalDescriptionTemplate::RESULT_MAX, mb_strlen($text));
    }

    public function testRender_DoesNotExpandAPlaceholderTypedInsideAValue(): void
    {
        $this->assertSame('Omar {number}', JournalDescriptionTemplate::render('{party}', ['party' => 'Omar {number}', 'number' => '99']));
        $this->assertSame('A {party}', JournalDescriptionTemplate::render('{number}', ['number' => 'A {party}', 'party' => 'Omar']));
        $this->assertSame('A {date} 2026-01-01', JournalDescriptionTemplate::render('{party} {date}', ['party' => 'A {date}', 'date' => '2026-01-01']));
    }
}
