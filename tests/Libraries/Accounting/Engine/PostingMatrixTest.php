<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\PostingExpression;
use App\Libraries\Accounting\Engine\PostingFacts;
use App\Libraries\Accounting\Engine\PostingRules;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

/**
 * T-PM: every line of the posting rules is checked against made-up documents whose journal is known
 * from the posting matrix, rule by rule. A rule that no case covers fails the test, so a rule added to
 * the table needs its case here.
 */
class PostingMatrixTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * @var array<string, int>
     */
    private array $roles = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $treasury    = (new TreasuryService())->create(['name' => 'Matrix drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->roles = [
            'treasury'         => (int) (new TreasuryService())->accountOf($treasury),
            'treasury_id'      => $treasury,
            'cash'             => $this->accountId('cash'),
            'other_expenses'   => $this->accountId('expenses_default'),
            'chosen_expense'   => $this->customAccount('52', '5298', 'Chosen expense'),
            'vat_input'        => $this->accountId('tax_input'),
            'other_payables'   => $this->accountId('other_payables'),
        ];
    }

    /**
     * Each case: the rules of the posting matrix it checks, the operation, the facts of a made-up
     * document (an account written as @role is the account of that role), and the lines expected as
     * role, side and amount.
     *
     * @return array<string, array{list<string>, string, array<string, mixed>, list<array{string, string, string}>}>
     */
    public static function caseProvider(): array
    {
        return [
            'P-45 P-46 an expense paid from a treasury with tax in its amount' => [
                ['P-45', 'P-46'], 'expense',
                ['figures' => ['gross' => '115.00', 'tax' => '15.00', 'net' => '100.00'], 'fields' => ['category_account_id' => null, 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['is_due' => false, 'has_treasury' => true, 'no_treasury' => false]],
                [['treasury', 'credit', '115.0000'], ['other_expenses', 'debit', '100.0000'], ['vat_input', 'debit', '15.0000']],
            ],
            'P-45 an expense of a category with an account and no tax, paid in cash' => [
                ['P-45'], 'expense',
                ['figures' => ['gross' => '80.00', 'tax' => '0', 'net' => '80.00'], 'fields' => ['category_account_id' => '@chosen_expense', 'treasury_id' => null, 'payment_type' => 'Cash'], 'flags' => ['is_due' => false, 'has_treasury' => false, 'no_treasury' => true]],
                [['cash', 'credit', '80.0000'], ['chosen_expense', 'debit', '80.0000']],
            ],
            'P-45 P-46 an expense not paid yet is owed to other creditors' => [
                ['P-45', 'P-46'], 'expense',
                ['figures' => ['gross' => '230.00', 'tax' => '30.00', 'net' => '200.00'], 'fields' => ['category_account_id' => null, 'treasury_id' => '@treasury_id', 'payment_type' => 'Due'], 'flags' => ['is_due' => true, 'has_treasury' => false, 'no_treasury' => false]],
                [['other_payables', 'credit', '230.0000'], ['other_expenses', 'debit', '200.0000'], ['vat_input', 'debit', '30.0000']],
            ],
            'P-45 P-46 money that came back turns every line around' => [
                ['P-45', 'P-46'], 'expense',
                ['figures' => ['gross' => '-115.00', 'tax' => '-15.00', 'net' => '-100.00'], 'fields' => ['category_account_id' => null, 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['is_due' => false, 'has_treasury' => true, 'no_treasury' => false]],
                [['treasury', 'debit', '115.0000'], ['other_expenses', 'credit', '100.0000'], ['vat_input', 'credit', '15.0000']],
            ],
        ];
    }

    /**
     * @param list<string>                         $references
     * @param array<string, mixed>                 $facts
     * @param list<array{string, string, string}>  $expected
     */
    #[DataProvider('caseProvider')]
    public function testBuild_PostsTheLinesThePostingMatrixSaysForTheRule(array $references, string $operation, array $facts, array $expected): void
    {
        $draft = (new PostingEngine())->build($this->facts($operation, $facts));
        $names = array_flip(array_diff_key($this->roles, ['treasury_id' => true]));

        $this->assertNotNull($draft);
        $this->assertSame(
            $expected,
            array_map(static function ($line) use ($names): array {
                $debit = bccomp($line->debit, '0', 4) > 0;

                return [$names[$line->accountId] ?? 'account ' . $line->accountId, $debit ? 'debit' : 'credit', bcadd($debit ? $line->debit : $line->credit, '0', 4)];
            }, $draft->lines),
            implode(' ', $references),
        );
    }

    public function testRules_HaveACaseForEveryRuleOfTheMatrixAndEveryLineComesToSomethingInACase(): void
    {
        $covered = [];
        $reached = [];

        foreach (self::caseProvider() as [$references, $operation, $facts]) {
            foreach ($references as $reference) {
                $covered[$operation][$reference] = true;
            }

            foreach ((new PostingRules())->current($operation, '2026-10-21')->lines as $line) {
                if (bccomp(PostingExpression::evaluate($line->amount, $facts['figures']), '0', 4) !== 0) {
                    $reached[$operation][$line->lineNo] = true;
                }
            }
        }

        foreach ((new PostingRules())->versions() as $set) {
            foreach ($set->lines as $line) {
                $this->assertNotNull($line->matrixRef, "Line {$line->lineNo} of {$set->operation} version {$set->version} names no rule of the matrix");
                $this->assertArrayHasKey($line->matrixRef, $covered[$set->operation] ?? [], "{$line->matrixRef} of {$set->operation} has no case");
                $this->assertArrayHasKey($line->lineNo, $reached[$set->operation] ?? [], "Line {$line->lineNo} of {$set->operation} comes to nothing in every case");
            }
        }
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function facts(string $operation, array $facts): PostingFacts
    {
        $fields = array_map(fn (mixed $value): mixed => is_string($value) && str_starts_with($value, '@') ? $this->roles[substr($value, 1)] : $value, $facts['fields'] ?? []);

        return new PostingFacts($operation, 1, '2026-10-21', 1, 1, 1, $facts['figures'], $fields, $facts['flags'] ?? []);
    }
}
