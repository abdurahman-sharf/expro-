<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Ai\FindingService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * What the scan found, for people to read and to decide: the filters, the scope of the branches, and the decision,
 * which is made once, with a reason, by a person.
 *
 * @internal
 */
final class FindingServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private FindingService $service;
    private int $reader;
    private int $counter = 0;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->service = new FindingService();
        $this->reader  = $this->agentEmployee();
        $this->counter = 0;
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function finding(string $rule = 'AI-ROUND', string $severity = 'low', ?int $branchId = 1, string $date = '2026-10-10', string $status = 'open'): int
    {
        $this->counter++;

        $this->accountingDb()->table('accounting_ai_findings')->insert([
            'rule_code'       => $rule,
            'severity'        => $severity,
            'finding_key'     => hash('sha256', 'key' . $this->counter),
            'branch_id'       => $branchId,
            'subject_type'    => 'journal',
            'subject_id'      => $this->counter,
            'occurred_on'     => $date,
            'template_args'   => json_encode(['number' => '00000' . $this->counter, 'amount' => '25000.0000', 'unit' => '1000.0000']),
            'evidence'        => json_encode([['type' => 'journal', 'journal_id' => $this->counter, 'number' => '00000' . $this->counter]]),
            'status'          => $status,
            'decided_by'      => $status === 'open' ? null : $this->reader,
            'decided_at'      => $status === 'open' ? null : '2026-10-11 10:00:00',
            'decision_reason' => $status === 'open' ? null : 'Looked at',
        ]);

        return (int) $this->accountingDb()->insertID();
    }

    private function refused(callable $call): AccountingException
    {
        try {
            $call();
        } catch (AccountingException $failure) {
            return $failure;
        }

        $this->fail('The call was not refused.');
    }

    /**
     * @param array{total: int, rows: list<object>} $result
     *
     * @return list<int>
     */
    private function ids(array $result): array
    {
        return array_map(static fn (object $row): int => (int) $row->finding_id, $result['rows']);
    }

    public function testSearch_ListsTheNewestFirstWithTheMessageFilledFromTheTemplateAndTheEvidence(): void
    {
        $one = $this->finding();
        $two = $this->finding('AI-DUPLICATE', 'medium');

        $result = $this->service->search([], $this->reader);

        $this->assertSame(2, $result['total']);
        $this->assertSame([$two, $one], $this->ids($result));
        $this->assertSame('HQ', $result['rows'][1]->branch_code);
        $this->assertStringContainsString('25000', $result['rows'][1]->message);
        $this->assertStringContainsString('000001', $result['rows'][1]->message);
        $this->assertCount(1, $result['rows'][1]->evidence_rows);
    }

    public function testSearch_FiltersByStatusRuleSeverityAndDate(): void
    {
        $open      = $this->finding('AI-ROUND', 'low', 1, '2026-10-10');
        $dismissed = $this->finding('AI-DUPLICATE', 'medium', 1, '2026-10-12', 'dismissed');
        $confirmed = $this->finding('AI-BACKDATED', 'high', 1, '2026-10-14', 'confirmed');

        $this->assertSame([$dismissed], $this->ids($this->service->search(['status' => 'dismissed'], $this->reader)));
        $this->assertSame([$confirmed], $this->ids($this->service->search(['rule' => 'AI-BACKDATED'], $this->reader)));
        $this->assertSame([$confirmed], $this->ids($this->service->search(['severity' => 'high'], $this->reader)));
        $this->assertSame([$dismissed, $open], $this->ids($this->service->search(['to' => '2026-10-12'], $this->reader)));
        $this->assertSame([$confirmed, $dismissed], $this->ids($this->service->search(['from' => '2026-10-12'], $this->reader)));
        $this->assertSame([$dismissed], $this->ids($this->service->search(['from' => '2026-10-11', 'to' => '2026-10-13'], $this->reader)));
        $this->assertSame(3, $this->service->search(['status' => 'nonsense', 'severity' => 'huge', 'from' => 'x'], $this->reader)['total']);
    }

    public function testSearch_PagesTheResult(): void
    {
        $ids = [$this->finding(), $this->finding(), $this->finding()];

        $page = $this->service->search([], $this->reader, 2, 1);

        $this->assertSame(3, $page['total']);
        $this->assertSame([$ids[1], $ids[0]], $this->ids($page));
    }

    public function testSearch_KeepsToTheBranchesOfTheReaderAndShowsTheOnesOfNoBranchToEveryone(): void
    {
        $branch = $this->createBranch();
        $mine   = $this->finding('AI-ROUND', 'low', 1);
        $other  = $this->finding('AI-ROUND', 'low', $branch);
        $whole  = $this->finding('AI-ROUND', 'low', null);

        $this->assertSame([$whole, $mine], $this->ids($this->service->search([], $this->agentEmployee([], [1]))));
        $this->assertSame([$whole, $other, $mine], $this->ids($this->service->search([], $this->reader)));
        $this->assertSame([$whole], $this->ids($this->service->search([], $this->agentEmployee([], []))));
    }

    public function testSearch_RequiresThePermissionToUseTheAgent(): void
    {
        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->service->search([], $this->employeeWithGrants([])))->error);
    }

    public function testSearch_ReadsTheHistoryWhileTheAgentIsSwitchedOff(): void
    {
        $this->finding();
        $this->setConfigValue('accounting_ai_enabled', '0');

        $this->assertSame(1, $this->service->search([], $this->reader)['total']);
    }

    public function testCounts_CountsTheFindingsOfEachStatusTheReaderMaySee(): void
    {
        $branch = $this->createBranch();
        $this->finding();
        $this->finding('AI-ROUND', 'low', 1, '2026-10-10', 'dismissed');
        $this->finding('AI-ROUND', 'low', $branch, '2026-10-10', 'confirmed');

        $this->assertSame(['open' => 1, 'dismissed' => 1, 'confirmed' => 1], $this->service->counts($this->reader));
        $this->assertSame(['open' => 1, 'dismissed' => 1, 'confirmed' => 0], $this->service->counts($this->agentEmployee([], [1])));
    }

    public function testCounts_RequiresThePermissionToUseTheAgent(): void
    {
        $this->finding();

        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->service->counts($this->employeeWithGrants([])))->error);
    }

    public function testRules_ListsTheRulesThatHaveAFinding(): void
    {
        $this->finding('AI-ROUND');
        $this->finding('AI-DUPLICATE');
        $this->finding('AI-ROUND');

        $this->assertSame(['AI-DUPLICATE', 'AI-ROUND'], $this->service->rules());
    }

    public function testFind_ReturnsTheFindingAndRefusesOneOutsideTheScope(): void
    {
        $branch = $this->createBranch();
        $mine   = $this->finding('AI-ROUND', 'low', 1);
        $other  = $this->finding('AI-ROUND', 'low', $branch);

        $this->assertSame($mine, (int) $this->service->find($mine, $this->reader)->finding_id);
        $this->assertSame(AccountingError::AiFindingNotFound, $this->refused(fn () => $this->service->find($other, $this->agentEmployee([], [1])))->error);
        $this->assertSame(AccountingError::AiFindingNotFound, $this->refused(fn () => $this->service->find(999999, $this->reader))->error);
    }

    public function testFind_RequiresThePermissionToUseTheAgent(): void
    {
        $id = $this->finding();

        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->service->find($id, $this->employeeWithGrants([])))->error);
    }

    public function testDecide_DismissesAnOpenFindingWithTheReasonAndWhoDecided(): void
    {
        $id = $this->finding();

        $this->service->decide($id, 'dismissed', '  Budgeted rent  ', $this->reader);

        $row = $this->service->find($id, $this->reader);

        $this->assertSame('dismissed', $row->status);
        $this->assertSame('Budgeted rent', $row->decision_reason);
        $this->assertSame($this->reader, (int) $row->decided_by);
        $this->assertNotNull($row->decided_at);
        $this->assertNotSame('', $row->decider_name);
    }

    public function testDecide_ConfirmsAnOpenFinding(): void
    {
        $id = $this->finding();

        $this->service->decide($id, 'confirmed', 'A real mistake', $this->reader);

        $this->assertSame('confirmed', $this->service->find($id, $this->reader)->status);
    }

    public function testDecide_WritesAnAuditEntryWithTheReason(): void
    {
        $id = $this->finding('AI-DUPLICATE', 'medium');

        $this->service->decide($id, 'confirmed', 'Entered twice', $this->reader);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where('action', 'finding_confirmed')->get()->getResult();

        $this->assertCount(1, $entries);
        $this->assertSame('ai_finding', $entries[0]->entity_type);
        $this->assertSame($id, (int) $entries[0]->entity_id);
        $this->assertSame($this->reader, (int) $entries[0]->employee_id);
        $this->assertSame('user', $entries[0]->origin);
        $this->assertSame(1, (int) $entries[0]->branch_id);
        $this->assertSame(['rule' => 'AI-DUPLICATE', 'reason' => 'Entered twice'], json_decode((string) $entries[0]->details, true));
    }

    public function testDecide_RefusesASecondDecision(): void
    {
        $id = $this->finding();
        $this->service->decide($id, 'dismissed', 'Fine', $this->reader);

        $this->assertSame(AccountingError::AiFindingDecided, $this->refused(fn () => $this->service->decide($id, 'confirmed', 'Changed my mind', $this->reader))->error);
        $this->assertSame('dismissed', $this->service->find($id, $this->reader)->status);
    }

    public function testDecide_RefusesAFindingThatVanishedBetweenTheReadAndTheLock(): void
    {
        $id   = $this->finding();
        $gone = false;

        $listener = function ($query) use ($id, &$gone): void {
            if (! $gone && str_contains((string) $query->getQuery(), 'decider_name')) {
                $gone = true;
                $this->openSecondConnection()->table('accounting_ai_findings')->where('finding_id', $id)->delete();
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $failure = $this->refused(fn () => $this->service->decide($id, 'dismissed', 'Fine', $this->reader));
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertSame(AccountingError::AiFindingNotFound, $failure->error);
    }

    public function testDecide_RefusesADecisionThatIsNeitherOfTheTwo(): void
    {
        $id = $this->finding();

        foreach (['open', 'approved', ''] as $decision) {
            $this->assertSame(AccountingError::AiDecisionInvalid, $this->refused(fn () => $this->service->decide($id, $decision, 'Reason', $this->reader))->error);
        }

        $this->assertSame('open', $this->service->find($id, $this->reader)->status);
    }

    public function testDecide_RequiresAReasonOfAtMostTwoHundredFiftyFiveCharacters(): void
    {
        $id = $this->finding();

        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            $this->assertSame(AccountingError::ReasonRequired, $this->refused(fn () => $this->service->decide($id, 'dismissed', $reason, $this->reader))->error);
        }

        $this->service->decide($id, 'dismissed', str_repeat('x', 255), $this->reader);

        $this->assertSame('dismissed', $this->service->find($id, $this->reader)->status);
    }

    public function testDecide_RequiresThePermissionAndAPerson(): void
    {
        $id = $this->finding();

        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->service->decide($id, 'dismissed', 'Fine', $this->employeeWithGrants([AccountingGuard::JOURNALS_POST])))->error);
        $this->assertSame(AccountingError::AiApproverRequired, $this->refused(fn () => $this->service->decide($id, 'dismissed', 'Fine', null))->error);
        $this->assertSame('open', $this->service->find($id, $this->reader)->status);
    }

    public function testDecide_AsksForThePermissionBeforeItChecksWhatWasAsked(): void
    {
        $id     = $this->finding();
        $nobody = $this->employeeWithGrants([]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->service->decide($id, 'maybe', '', $nobody))->error);
        $this->assertSame(AccountingError::AiDecisionInvalid, $this->refused(fn () => $this->service->decide($id, 'maybe', 'Fine', $this->reader))->error);
    }

    public function testDecide_RefusesAFindingOutsideTheBranchScope(): void
    {
        $branch = $this->createBranch();
        $id     = $this->finding('AI-ROUND', 'low', $branch);

        $this->assertSame(AccountingError::AiFindingNotFound, $this->refused(fn () => $this->service->decide($id, 'dismissed', 'Fine', $this->agentEmployee([], [1])))->error);
        $this->assertSame('open', $this->service->find($id, $this->reader)->status);
    }

    public function testDecide_WorksWhileTheAgentIsSwitchedOff(): void
    {
        $id = $this->finding();
        $this->setConfigValue('accounting_ai_enabled', '0');

        $this->service->decide($id, 'dismissed', 'Fine', $this->reader);

        $this->assertSame('dismissed', $this->service->find($id, $this->reader)->status);
    }

    public function testStream_GivesEveryFindingOfTheFiltersNewestFirstAndKeepsToTheScope(): void
    {
        $branch = $this->createBranch();
        $one    = $this->finding('AI-ROUND', 'low', 1);
        $two    = $this->finding('AI-DUPLICATE', 'medium', 1);
        $this->finding('AI-ROUND', 'low', $branch);

        $this->assertSame([$two, $one], array_map(static fn (object $row): int => (int) $row->finding_id, iterator_to_array($this->service->stream([], $this->agentEmployee([], [1])), false)));
        $this->assertSame([$one], array_map(static fn (object $row): int => (int) $row->finding_id, iterator_to_array($this->service->stream(['rule' => 'AI-ROUND'], $this->agentEmployee([], [1])), false)));
    }

    public function testSearch_GivesAtMostTheLargestPageWhateverIsAsked(): void
    {
        $this->findings(FindingService::PAGE_MAX + 20);

        $result = $this->service->search([], $this->reader, 10000);

        $this->assertSame(FindingService::PAGE_MAX + 20, $result['total']);
        $this->assertCount(FindingService::PAGE_MAX, $result['rows']);
        $this->assertCount(1, $this->service->search([], $this->reader, 0)['rows']);
        $this->assertCount(20, $this->service->search([], $this->reader, 100, FindingService::PAGE_MAX)['rows']);
        $this->assertCount(FindingService::PAGE_MAX, $this->service->search([], $this->reader, 500, -5)['rows']);
    }

    public function testStream_GivesAtMostTheLargestFileAndEveryFindingBelowIt(): void
    {
        $this->findings(FindingService::EXPORT_MAX + 7);

        $count = 0;
        $first = null;
        $last  = null;

        foreach ($this->service->stream([], $this->reader) as $row) {
            $first ??= (int) $row->finding_id;
            $last = (int) $row->finding_id;
            $count++;
        }

        $this->assertSame(FindingService::EXPORT_MAX, $count);
        $this->assertSame($first - FindingService::EXPORT_MAX + 1, $last);
    }

    public function testStream_WalksThePagesWithoutRepeatingOrLeavingOneOut(): void
    {
        $this->findings(1200);

        $ids = array_map(static fn (object $row): int => (int) $row->finding_id, iterator_to_array($this->service->stream([], $this->reader), false));

        $this->assertCount(1200, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
    }

    /**
     * @return void Inserts findings without evidence worth the name, as fast as the table takes them.
     */
    private function findings(int $count): void
    {
        $base = $this->counter;

        for ($offset = 0; $offset < $count; $offset += 2000) {
            $rows = [];

            for ($index = $offset; $index < min($count, $offset + 2000); $index++) {
                $rows[] = [
                    'rule_code'    => 'AI-ROUND',
                    'severity'     => 'low',
                    'finding_key'  => hash('sha256', 'bulk' . $base . '-' . $index),
                    'branch_id'    => 1,
                    'subject_type' => 'journal',
                    'subject_id'   => $index + 1,
                    'occurred_on'  => '2026-10-10',
                    'evidence'     => '[]',
                ];
            }

            $this->accountingDb()->table('accounting_ai_findings')->insertBatch($rows);
        }

        $this->counter += $count;
    }

    public function testStream_RequiresThePermission(): void
    {
        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => iterator_to_array($this->service->stream([], $this->employeeWithGrants([])), false))->error);
    }

    public function testTemplateOf_NamesTheTemplateOfARuleCode(): void
    {
        $this->assertSame('ai_balance_jump', FindingService::templateOf('AI-BALANCE-JUMP'));
    }

    public function testTable_RefusesAStatusASeverityAndADecisionThatDoNotAgree(): void
    {
        $id = $this->finding();

        foreach ([['status' => 'done'], ['severity' => 'urgent'], ['status' => 'dismissed'], ['decided_by' => 1]] as $change) {
            try {
                $this->accountingDb()->table('accounting_ai_findings')->where('finding_id', $id)->update($change);
                $this->fail('The table accepted ' . json_encode($change));
            } catch (DatabaseException) {
                $this->assertSame('open', $this->accountingDb()->table('accounting_ai_findings')->where('finding_id', $id)->get()->getRow()->status);
            }
        }
    }

    public function testTable_KeepsOneFindingForOneKey(): void
    {
        $id  = $this->finding();
        $key = $this->accountingDb()->table('accounting_ai_findings')->where('finding_id', $id)->get()->getRow()->finding_key;

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_ai_findings')->insert(['rule_code' => 'AI-ROUND', 'severity' => 'low', 'finding_key' => $key, 'subject_type' => 'journal', 'evidence' => '[]']);
    }
}
