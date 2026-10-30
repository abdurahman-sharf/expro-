<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\ReadinessRunbook;
use CodeIgniter\Test\CIUnitTestCase;

class ReadinessRunbookTest extends CIUnitTestCase
{
    /**
     * @return list<array{version: string, class: string, kind: string, note: string}>
     */
    private function pending(): array
    {
        return [
            ['version' => '20261029000000', 'class' => 'LegacyFixedPurchaseDiscount', 'kind' => 'data', 'note' => 'marks old discounts'],
            ['version' => '20270200000001', 'class' => 'CrossBranchPayments', 'kind' => 'heavy', 'note' => ''],
            ['version' => '20261250000001', 'class' => 'JournalDetailsPermission', 'kind' => 'additive', 'note' => ''],
        ];
    }

    public function testSteps_GivesTheStepsInTheOrderOfTheDeployment(): void
    {
        $titles = array_column(ReadinessRunbook::steps([]), 'title');

        $this->assertCount(8, $titles);
        $this->assertStringStartsWith('Back up', $titles[0]);
        $this->assertStringStartsWith('Readiness before the migrations', $titles[1]);
        $this->assertStringStartsWith('Decide', $titles[2]);
        $this->assertStringStartsWith('Run the migrations', $titles[3]);
        $this->assertStringStartsWith('Post-checks', $titles[4]);
        $this->assertStringStartsWith('Review who holds', $titles[5]);
        $this->assertStringStartsWith('Turn accounting on only when the owner orders it', $titles[6]);
        $this->assertStringStartsWith('Roll back by restoring the backup', $titles[7]);
    }

    public function testSteps_BacksUpBeforeAnythingElseWithADumpAndTheFiles(): void
    {
        $step = ReadinessRunbook::steps([])[0];

        $this->assertStringStartsWith('mysqldump --single-transaction', $step['commands'][0]);
        $this->assertStringContainsString('writable/', implode(' ', $step['notes']));
    }

    public function testSteps_ChecksReadinessBeforeAndAfterTheMigrations(): void
    {
        $steps = ReadinessRunbook::steps([]);

        $this->assertContains('php spark accounting:readiness', $steps[1]['commands']);
        $this->assertContains('php spark accounting:readiness --json > readiness-before.json', $steps[1]['commands']);
        $this->assertContains('php spark accounting:readiness', $steps[4]['commands']);
        $this->assertContains('php spark accounting:bank_accounts', $steps[4]['commands']);
        $this->assertContains('php spark accounting:check_locks', $steps[4]['commands']);
    }

    public function testSteps_NamesTheCommandsThatDecideWhatTheReportFails(): void
    {
        $commands = implode("\n", ReadinessRunbook::steps([])[2]['commands']);

        $this->assertStringContainsString('accounting:purchase_discount resolve --before', $commands);
        $this->assertStringContainsString('accounting:stamp_payments', $commands);
        $this->assertStringContainsString('accounting:bank_accounts', $commands);
    }

    public function testSteps_ListsThePendingMigrationsInTheGivenOrderEachWithItsKind(): void
    {
        $notes = ReadinessRunbook::steps($this->pending())[3]['notes'];

        $this->assertSame('20261029000000 LegacyFixedPurchaseDiscount [DATA]: marks old discounts', $notes[0]);
        $this->assertSame('20270200000001 CrossBranchPayments [HEAVY]', $notes[1]);
        $this->assertSame('20261250000001 JournalDetailsPermission [ADDITIVE]', $notes[2]);
    }

    public function testSteps_SaysNoMigrationIsPendingWhenNoneIs(): void
    {
        $this->assertSame('No migration is pending.', ReadinessRunbook::steps([])[3]['notes'][0]);
    }

    public function testSteps_ExplainsWhatTheKindsMeanAndToStopAtTheFirstFailure(): void
    {
        $notes = implode("\n", ReadinessRunbook::steps([])[3]['notes']);

        $this->assertStringContainsString('DATA: rewrites or classifies existing rows', $notes);
        $this->assertStringContainsString('HEAVY: alters a large table', $notes);
        $this->assertStringContainsString('Stop at the first failure and restore the backup', $notes);
    }

    public function testSteps_KeepsAccountingOffUntilTheOwnerOrdersIt(): void
    {
        $step = ReadinessRunbook::steps([])[6];

        $this->assertStringContainsString('accounting:fill_accounts', implode("\n", $step['commands']));
        $this->assertStringContainsString('Accounting stays disabled on a real business until then', implode("\n", $step['notes']));
    }

    public function testSteps_RollsBackByRestoringTheBackupBecauseDownDoesNotUndoData(): void
    {
        $step = ReadinessRunbook::steps([])[7];

        $this->assertStringStartsWith('mysql ', $step['commands'][0]);
        $this->assertStringContainsString('down() is not a rollback for the data migrations', implode("\n", $step['notes']));
    }

    public function testSteps_GivesEveryStepATitleAndLists(): void
    {
        foreach (ReadinessRunbook::steps($this->pending()) as $step) {
            $this->assertNotSame('', $step['title']);
            $this->assertIsArray($step['commands']);
            $this->assertNotSame([], $step['notes']);
        }
    }
}
