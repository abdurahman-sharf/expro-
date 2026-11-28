<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\Engine\PostingRules;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

class PostingRulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PostingRules $rules;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountingDb()->table('accounting_posting_rule_sets')->where('operation', 'probe')->delete();
        $this->rules = new PostingRules();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('accounting_posting_rule_sets')->where('operation', 'probe')->delete();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'posting_rules')->delete();

        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(string $amount = 'net'): array
    {
        return [
            ['line_no' => 1, 'side' => 'debit', 'account' => ['first' => [['role' => 'expenses.default']]], 'amount' => $amount],
            ['line_no' => 2, 'side' => 'credit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => $amount],
        ];
    }

    public function testPublish_WritesANewVersionAndTheAuditLogWithTheEmployeeAndLeavesTheJournalsAlone(): void
    {
        $journals = $this->countRows('accounting_journals');

        $first  = $this->rules->publish('probe', '2026-01-01', ['source_type' => 'probe'], $this->lines(), 1);
        $second = $this->rules->publish('probe', '2026-06-01', ['source_type' => 'probe'], $this->lines('net + tax'), 1);

        $this->assertSame([2, 1], array_map(static fn ($set): int => $set->version, $this->rules->versions('probe')));
        $this->assertSame($journals, $this->countRows('accounting_journals'));

        $audit = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'posting_rules', 'entity_id' => $second])->get()->getRow();

        $this->assertSame(['published', '1'], [$audit->action, (string) $audit->employee_id]);
        $this->assertSame(['operation' => 'probe', 'version' => 2, 'effective_from' => '2026-06-01', 'lines' => 2], json_decode((string) $audit->details, true));
        $this->assertNotSame($first, $second);
    }

    public function testCurrent_TakesTheVersionThatAppliesOnTheDay(): void
    {
        $this->rules->publish('probe', '2026-01-01', ['source_type' => 'probe'], $this->lines(), 1);
        $this->rules->publish('probe', '2026-06-01', ['source_type' => 'probe'], $this->lines('net + tax'), 1);

        $this->assertSame([1, 'net'], [$this->rules->current('probe', '2026-05-31')->version, $this->rules->current('probe', '2026-05-31')->lines[0]->amount]);
        $this->assertSame([2, 'net + tax'], [$this->rules->current('probe', '2026-06-01')->version, $this->rules->current('probe', '2026-06-01')->lines[0]->amount]);
    }

    public function testCurrent_HoldsTheRulesOfTheExpenseForEveryDay(): void
    {
        $set = $this->rules->current('expense', '1999-12-31');

        $this->assertSame(['expense', true, 3], [$set->sourceType, $set->crossBranch, count($set->lines)]);
        $this->assertSame([['credit', 'gross'], ['debit', 'net'], ['debit', 'tax']], array_map(static fn ($line): array => [$line->side, $line->amount], $set->lines));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidLineProvider(): array
    {
        return [
            'an amount that is not an expression' => [['amount' => 'exec(net)']],
            'a side that is not one'              => [['side' => 'both']],
            'a routing row that is not one'       => [['account' => ['first' => [['role' => 'sales.nothing']]]]],
            'a place of two kinds'                => [['account' => ['first' => [['role' => 'tax.input', 'system' => 'cash']]]]],
            'a key that is not known'             => [['account' => ['first' => [['sql' => 'select']]]]],
            'no place'                            => [['account' => ['first' => []]]],
            'a failure that is not known'         => [['account' => ['first' => [['system' => 'cash']], 'else' => 'anything']]],
            'no journal as the failure'           => [['account' => ['first' => [['system' => 'cash']], 'else' => 'no_journal']]],
            'no journal without a condition'      => [['account' => ['first' => [['no_journal' => true], ['system' => 'cash']]]]],
            'no journal that is not true'         => [['account' => ['first' => [['when' => 'on_credit', 'no_journal' => 'yes'], ['system' => 'cash']]]]],
            'no journal beside an account'        => [['account' => ['first' => [['when' => 'on_credit', 'no_journal' => true, 'system' => 'cash']]]]],
            'an entity on a system account'       => [['account' => ['first' => [['system' => 'cash', 'entity' => 'x']]]]],
            'a cost center that is not a field'   => [['cost_center' => ['role' => 'x']]],
            'a foreign amount that is not one'    => [['foreign_amount' => 'net;']],
        ];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('invalidLineProvider')]
    public function testPublish_RejectsALineThatIsNotValidAndWritesNothing(array $change): void
    {
        $lines    = $this->lines();
        $lines[0] = array_merge($lines[0], $change);

        try {
            $this->rules->publish('probe', '2026-01-01', ['source_type' => 'probe'], $lines, 1);
            $this->fail('The rules were published.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::RuleInvalid, $failure->error);
        }

        $this->assertSame([], $this->rules->versions('probe'));
    }

    public function testPublish_RejectsTwoLinesWithOneNumberAndADayThatIsNotADate(): void
    {
        foreach ([[$this->lines()[0], $this->lines()[0]], 'not-a-day'] as $case) {
            try {
                $this->rules->publish('probe', is_string($case) ? $case : '2026-01-01', ['source_type' => 'probe'], is_string($case) ? $this->lines() : $case, 1);
                $this->fail('The rules were published.');
            } catch (PostingException $failure) {
                $this->assertSame(PostingError::RuleInvalid, $failure->error);
            }
        }

        $this->assertSame([], $this->rules->versions('probe'));
    }
}
