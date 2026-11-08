<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\Ai\CounterpartText;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class CounterpartTextTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    #[DataProvider('texts')]
    public function testNormalize_MakesTheSpacesAndTheCaseEqualAndCutsTheLength(string $text, string $expected): void
    {
        $this->assertSame($expected, CounterpartText::normalize($text));
    }

    public function testNormalize_GivesNothingForNothing(): void
    {
        $this->assertSame('', CounterpartText::normalize(null));
        $this->assertSame('', CounterpartText::normalize("  \t "));
    }

    #[DataProvider('texts')]
    public function testSql_GivesTheTextOfTheLineAsNormalizeGivesIt(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->sqlText($text, 'Journal words'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function texts(): iterable
    {
        return [
            'spaces made equal'      => ["Office   rent \t of  the\nshop", 'office rent of the shop'],
            'ends trimmed'           => ['   Office rent   ', 'office rent'],
            'a form feed at the end' => ["\x0cOffice rent", 'office rent'],
            'case made equal'        => ['ELECTRICITY Bill', 'electricity bill'],
            'letters with accents'   => ['ÉLECTRICITÉ', 'électricité'],
            'arabic text'            => ['إيجار   المكتب', 'إيجار المكتب'],
            'cut to the length'      => [str_repeat('Ab ', 60), mb_substr(str_repeat('ab ', 60), 0, CounterpartText::MAX)],
            'cut after a space'      => [str_repeat('a', CounterpartText::MAX - 1) . ' tail', str_repeat('a', CounterpartText::MAX - 1) . ' '],
        ];
    }

    public function testSql_GivesTheTextOfTheJournalWhenTheLineHasNone(): void
    {
        $this->assertSame('journal words', $this->sqlText('', '  Journal   WORDS '));
        $this->assertSame('journal words', $this->sqlText('   ', 'Journal words'));
        $this->assertSame('journal words', $this->sqlText(null, 'Journal words'));
    }

    public function testSql_GivesNothingWhenNeitherHasText(): void
    {
        $this->assertSame('', $this->sqlText('', ''));
        $this->assertSame('', $this->sqlText(null, null));
    }

    private function sqlText(?string $line, ?string $journal): string
    {
        $row = Database::connect()->query(
            'SELECT ' . CounterpartText::sql('l', 'j') . ' AS text FROM (SELECT ? AS description) l CROSS JOIN (SELECT ? AS description) j',
            [$line, $journal],
        )->getRow();

        return (string) $row->text;
    }
}
