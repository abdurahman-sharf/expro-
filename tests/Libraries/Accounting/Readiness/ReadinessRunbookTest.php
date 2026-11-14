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
        $this->assertContains('php spark accounting:readiness --json --no-header > readiness-before.json', $steps[1]['commands']);
        $this->assertContains('php spark accounting:readiness', $steps[4]['commands']);
        $this->assertContains('php spark accounting:readiness --json --no-header > readiness-after.json', $steps[4]['commands']);
        $this->assertContains('php spark accounting:bank_accounts', $steps[4]['commands']);
        $this->assertContains('php spark accounting:check_locks', $steps[4]['commands']);
    }

    public function testSteps_KeepsTheSparkBannerOutOfTheSavedJson(): void
    {
        $step = ReadinessRunbook::steps([])[1];

        $this->assertStringContainsString('--no-header', implode("\n", array_filter($step['commands'], static fn (string $command): bool => str_contains($command, '--json'))));
        $this->assertStringContainsString('without it spark prints its own banner', implode("\n", $step['notes']));
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

        $this->assertStringStartsWith('mysql ', end($step['commands']));
        $this->assertStringContainsString('< backup-before-release.sql', end($step['commands']));
        $this->assertStringContainsString('down() is not a rollback for the data migrations', implode("\n", $step['notes']));
    }

    public function testSteps_RollsBackIntoAnEmptyDatabaseSoThatNoTableOfTheReleaseSurvives(): void
    {
        $commands = ReadinessRunbook::steps([])[7]['commands'];
        $keeps    = $this->positionOf($commands, 'state-after-failed-release.sql');
        $drops    = $this->positionOf($commands, 'DROP DATABASE DATABASE');
        $restores = $this->positionOf($commands, '< backup-before-release.sql');

        $this->assertLessThan($drops, $keeps, 'What the failed release left is kept before the database is dropped.');
        $this->assertLessThan($restores, $drops, 'The database is dropped before the backup is restored.');
        $this->assertStringContainsString('CREATE DATABASE DATABASE CHARACTER SET utf8mb4', $commands[$drops]);
        $this->assertStringContainsString('restoring over the live database leaves the tables the release created', implode("\n", ReadinessRunbook::steps([])[7]['notes']));
    }

    public function testSteps_RehearsesTheRestoreInAScratchDatabaseAndNeverTheLiveOne(): void
    {
        $notes = implode("\n", ReadinessRunbook::steps([])[0]['notes']);

        $this->assertStringContainsString('mysql -u USER -p SCRATCH < backup-before-release.sql', $notes);
        $this->assertStringContainsString('Never name the live database there', $notes);
    }

    public function testSteps_ComparesTheReadinessBeforeAndAfterTheMigrationsByWhatTheScanCounted(): void
    {
        $notes = implode("\n", ReadinessRunbook::steps([])[4]['notes']);

        $this->assertStringContainsString('readiness-before.json with readiness-after.json', $notes);
        $this->assertStringContainsString('would_mark and would_mark_discount before must equal marked_lines and marked_discount after', $notes);
        $this->assertStringContainsString('TIE-SUPPLIERS and TIE-CUSTOMERS', $notes);
    }

    public function testSteps_RehearsesWithAReportTakenOnTheRestoredCopyAndSaysNoRehearsalIsRecorded(): void
    {
        $step = ReadinessRunbook::steps([])[0];

        $this->assertSame('mysql -u USER -p SCRATCH < backup-before-release.sql', $step['commands'][1]);
        $this->assertSame('MYSQL_DB_NAME=SCRATCH php spark accounting:readiness --json --no-header > readiness-rehearsal.json', $step['commands'][2]);
        $this->assertStringContainsString('nothing in this repository records that a restore was ever rehearsed', implode("\n", $step['notes']));
    }

    public function testSteps_AsksForTheClockOfTheDatabaseBeforeTheMigrationsAndNamesTheZoneOfTheCommandLine(): void
    {
        $step  = ReadinessRunbook::steps([])[1];
        $notes = implode("\n", $step['notes']);

        $this->assertStringContainsString('@@session.time_zone', implode("\n", $step['commands']));
        $this->assertStringContainsString('W93-CLOCK: the clock of the database and the clock of the shop must name the same day', $notes);
        $this->assertStringContainsString('sales_payments.payment_time', $notes);
        $this->assertStringContainsString('default-time-zone', $notes);
        $this->assertStringContainsString('only accounting:generate_recurring and accounting:depreciate apply the zone of the shop', $notes);
        $this->assertStringContainsString('app.appTimezone', $notes);
    }

    public function testSteps_ComparesTheTwoReportsWithTheComparisonCommandRightAfterTheSecondOne(): void
    {
        $commands = ReadinessRunbook::steps([])[4]['commands'];
        $notes    = implode("\n", ReadinessRunbook::steps([])[4]['notes']);
        $after    = array_search('php spark accounting:readiness --json --no-header > readiness-after.json', $commands, true);

        $this->assertNotFalse($after);
        $this->assertSame('php spark accounting:readiness_compare readiness-before.json readiness-after.json', $commands[$after + 1]);
        $this->assertStringContainsString('exits 0 when nothing is flagged, 1 when something is and 7 for a file it cannot read', $notes);
        $this->assertStringContainsString('data.aging_totals', $notes);
    }

    public function testSteps_NamesTheCronLinesAndWhatTheReportDoesNotCheck(): void
    {
        $notes = implode("\n", ReadinessRunbook::steps([])[6]['notes']);

        $this->assertStringContainsString('accounting:generate_recurring once a day at least', $notes);
        $this->assertStringContainsString('accounting:depreciate is run from cron only as the preview', $notes);
        $this->assertStringContainsString('accounting:ai_scan and accounting:ai_propose are left unscheduled until the owner switches the agent on', $notes);
        $this->assertStringContainsString('The report has no check for fixed assets, e-invoicing, the AI agent, the customer portal or API keys', $notes);
    }

    public function testSteps_SaysNoRunOnOlderEnginesIsRecorded(): void
    {
        $this->assertStringContainsString('No run of these migrations on MySQL 5.7 or MariaDB 10.5 is recorded', implode("\n", ReadinessRunbook::steps([])[3]['notes']));
    }

    public function testSteps_AgreeWithTheRunbookDocumentOnTheClockTheRehearsalTheComparisonAndTheCron(): void
    {
        $document = (string) file_get_contents(ROOTPATH . 'docs/accounting/readiness-runbook.md');

        foreach (['W93-CLOCK', 'app.appTimezone', 'default-time-zone', 'sales_payments.payment_time', 'MYSQL_DB_NAME', 'readiness-rehearsal.json', 'accounting:readiness_compare', 'data.aging_totals', 'accounting:depreciate', 'accounting:ai_scan', 'MySQL 5.7'] as $token) {
            $this->assertStringContainsString($token, $document, "The runbook document says nothing of {$token}, which the printed runbook does.");
        }
    }

    /**
     * @param list<string> $commands
     */
    private function positionOf(array $commands, string $needle): int
    {
        foreach ($commands as $position => $command) {
            if (str_contains($command, $needle)) {
                return $position;
            }
        }

        $this->fail("No command contains {$needle}.");
    }

    public function testSteps_GivesEveryStepATitleAndLists(): void
    {
        foreach (ReadinessRunbook::steps($this->pending()) as $step) {
            $this->assertNotSame('', $step['title']);
            $this->assertIsArray($step['commands']);
            $this->assertNotSame([], $step['notes']);
        }
    }

    public function testSteps_AgreeCommandForCommandWithTheRunbookDocument(): void
    {
        $document = file_get_contents(ROOTPATH . 'docs/accounting/readiness-runbook.md');
        $section  = substr($document, strpos($document, '## 3.'), strpos($document, '## 4.') - strpos($document, '## 3.'));

        preg_match_all('/```\n(.*?)\n```/s', $section, $blocks);

        $documented = array_values(array_filter(array_map('trim', explode("\n", implode("\n", $blocks[1])))));
        $built      = array_merge(...array_column(ReadinessRunbook::steps([]), 'commands'));

        $this->assertSame($built, $documented);
        $this->assertSame(count(ReadinessRunbook::steps([])), substr_count($section, "\n### "));
    }
}
