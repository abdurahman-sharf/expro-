<?php

namespace Tests\Commands;

use App\Commands\AccountingReadinessCompare;
use App\Libraries\Accounting\Readiness\ReadinessRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The comparison command reads two files taken with accounting:readiness --json, says what changed between
 * them and by its exit code whether anything is flagged. Its last cases take the two reports from the real
 * runner around a real invoice.
 *
 * @internal
 */
final class AccountingReadinessCompareTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /**
     * @var list<string>
     */
    private array $files = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $content
     */
    private function file(array $content): string
    {
        return $this->fileOf((string) json_encode($content, JSON_PRETTY_PRINT));
    }

    private function fileOf(string $text): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'readiness');

        file_put_contents($path, $text);

        $this->files[] = $path;

        return $path;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function check(string $id, string $status = 'pass', array $data = []): array
    {
        return ['id' => $id, 'ref' => strtoupper($id), 'title' => "Title of {$id}", 'status' => $status, 'summary' => '', 'findings' => [], 'data' => $data, 'apply' => null];
    }

    /**
     * @param list<array<string, mixed>> $checks
     *
     * @return array<string, mixed>
     */
    private function report(array $checks, string $phase = 'pre-migration', string $moment = '2026-11-14 01:00:00'): array
    {
        return ['ready' => true, 'exit_code' => 0, 'read_only' => true, 'phase' => $phase, 'database' => 'ospos', 'generated_at' => $moment, 'checks' => $checks];
    }

    /**
     * @param array<int|string, string|null> $params
     *
     * @return array{int, string}
     */
    private function compare(array $params): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('accounting:readiness_compare', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(string $output): array
    {
        return json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
    }

    private function customerTies(string $total): array
    {
        return $this->check('customer_ties', 'pass', ['checked' => 1, 'aging_totals' => [12 => $total], 'aging_total' => $total]);
    }

    public function testRun_ExitsZeroAndSaysNothingIsFlaggedForTwoReportsThatAgree(): void
    {
        $before = $this->file($this->report([$this->customerTies('100.0000')]));
        $after  = $this->file($this->report([$this->customerTies('100.0000')], 'post-migration', '2026-11-14 02:00:00'));

        [$exit, $output] = $this->compare([$before, $after]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('RESULT: NO DIFFERENCE FLAGGED', $output);
        $this->assertStringContainsString('READINESS COMPARISON', $output);
        $this->assertStringContainsString('database ospos, 2026-11-14 01:00:00, phase pre-migration.', $output);
        $this->assertStringContainsString('database ospos, 2026-11-14 02:00:00, phase post-migration.', $output);
    }

    public function testRun_ExitsOneAndNamesTheChangedAgingTotal(): void
    {
        $before = $this->file($this->report([$this->customerTies('100.0000')]));
        $after  = $this->file($this->report([$this->customerTies('120.0000')], 'post-migration', '2026-11-14 02:00:00'));

        [$exit, $output] = $this->compare([$before, $after]);

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/FLAGGED\s+CUSTOMER_TIES \(customer_ties\)/', $output);
        $this->assertStringContainsString('data.aging_totals.12: "100.0000" -> "120.0000"', $output);
        $this->assertStringContainsString('RESULT: DIFFERENCES FLAGGED in 1 of 1 check(s)', $output);
    }

    public function testRun_ExitsOneForACheckThatTurnedToFail(): void
    {
        $before = $this->file($this->report([$this->check('ledger_balance', 'pass', ['bad' => 0])]));
        $after  = $this->file($this->report([$this->check('ledger_balance', 'fail', ['bad' => 0])], 'post-migration', '2026-11-14 02:00:00'));

        [$exit, $output] = $this->compare([$before, $after]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('status pass -> fail', $output);
        $this->assertStringContainsString('! the check was pass in the first report and is FAIL in the second', $output);
    }

    public function testRun_ExitsZeroForWhatTheMigrationsAreExpectedToChange(): void
    {
        $before = $this->file($this->report([$this->check('migrations', 'review', ['pending' => [['version' => '1']]])]));
        $after  = $this->file($this->report([$this->check('migrations', 'pass', ['pending' => []])], 'post-migration', '2026-11-14 02:00:00'));

        [$exit, $output] = $this->compare([$before, $after]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('changed as the migrations are expected to change them', $output);
    }

    public function testRun_PrintsNotesAboutTheTwoReports(): void
    {
        $before = $this->file($this->report([$this->check('a')], 'post-migration'));
        $after  = $this->file($this->report([$this->check('a')], 'pre-migration', '2026-11-14 02:00:00'));

        [, $output] = $this->compare([$before, $after]);

        $this->assertStringContainsString('NOTE     The first report says post-migration', $output);
        $this->assertStringContainsString('NOTE     The second report still says pre-migration', $output);
    }

    public function testRun_PrintsOneJsonDocumentWithNothingElse(): void
    {
        $before = $this->file($this->report([$this->customerTies('100.0000')]));
        $after  = $this->file($this->report([$this->customerTies('120.0000')], 'post-migration', '2026-11-14 02:00:00'));

        [$exit, $output] = $this->compare([$before, $after, 'json' => null]);

        $document = $this->decoded($output);

        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('{', trim($output));
        $this->assertTrue($document['flagged']);
        $this->assertSame('pre-migration', $document['before']['phase']);
        $this->assertSame('post-migration', $document['after']['phase']);
        $this->assertSame('data.aging_totals.12', $document['differences'][0]['changes'][0]['path']);
    }

    public function testRun_PrintsOnlyTheFirstChangedValuesOfACheckUnlessAskedForAll(): void
    {
        $totals = static fn (string $amount): array => array_fill_keys(range(1, 30), $amount);
        $before = $this->file($this->report([$this->check('customer_ties', 'pass', ['aging_totals' => $totals('1.0000')])]));
        $after  = $this->file($this->report([$this->check('customer_ties', 'pass', ['aging_totals' => $totals('2.0000')])], 'post-migration', '2026-11-14 02:00:00'));

        [, $few]   = $this->compare([$before, $after]);
        [, $three] = $this->compare([$before, $after, 'limit' => '3']);
        [, $all]   = $this->compare([$before, $after, 'limit' => '0']);
        [, $json]  = $this->compare([$before, $after, 'json' => null, 'limit' => '3']);

        $this->assertSame(20, substr_count($few, '           - data.aging_totals.'));
        $this->assertStringContainsString('... and 10 more', $few);
        $this->assertSame(3, substr_count($three, '           - data.aging_totals.'));
        $this->assertSame(30, substr_count($all, '           - data.aging_totals.'));
        $this->assertCount(30, $this->decoded($json)['differences'][0]['changes']);
    }

    public function testRun_RefusesWhatItCannotRead(): void
    {
        $good     = $this->file($this->report([$this->check('a')]));
        $notJson  = $this->fileOf("Open Source Point of Sale\n{}");
        $noChecks = $this->fileOf('{"checks": []}');

        foreach (
            [
                'no file'             => [[], '/Name the report taken before/'],
                'one file'            => [[$good], '/Name the report taken before/'],
                'three files'         => [[$good, $good, $good], '/Name the report taken before/'],
                'a missing file'      => [[$good, '/nonexistent/readiness.json'], '/is not a file that can be read/'],
                'text, not JSON'      => [[$good, $notJson], '/it is not valid JSON/'],
                'a file of no checks' => [[$noChecks, $good], '/has no list of checks/'],
                'a bad limit'         => [[$good, $good, 'limit' => 'many'], '/The limit must be a whole number/'],
                'a limit of none'     => [[$good, $good, 'limit' => null], '/The limit must be a whole number/'],
            ] as $case => [$params, $message]
        ) {
            $this->resetStreamFilterBuffer();
            $exit = service('commands')->run('accounting:readiness_compare', $params);

            $this->assertSame(EXIT_USER_INPUT, $exit, "{$case} is refused with the user-input exit code.");
            $this->assertMatchesRegularExpression($message, $this->getStreamFilterBuffer(), "{$case} says why.");
            $this->assertStringNotContainsString('RESULT', $this->getStreamFilterBuffer());
        }
    }

    public function testRun_NamesTheFileThatCouldNotBeRead(): void
    {
        $good    = $this->file($this->report([$this->check('a')]));
        $notJson = $this->fileOf('nope');

        $this->resetStreamFilterBuffer();
        service('commands')->run('accounting:readiness_compare', [$good, $notJson]);

        $this->assertStringContainsString($notJson . ': it is not valid JSON', $this->getStreamFilterBuffer());
    }

    public function testRun_SaysInItsDescriptionAndInTheRunbookDocumentWhatItDoesAndHowItExits(): void
    {
        $command  = new AccountingReadinessCompare(service('logger'), service('commands'));
        $document = (string) file_get_contents(ROOTPATH . 'docs/accounting/readiness-runbook.md');

        $this->assertStringContainsString('Exits 0 when nothing is flagged, 1 when something is, and 7 for a file or an option it refuses', $command->description);
        $this->assertStringContainsString('php spark accounting:readiness_compare readiness-before.json readiness-after.json', $document);
    }

    public function testRun_ComparesTwoRealReportsAndFlagsTheAgingTotalOfACustomerWhoseInvoiceWasSavedBetweenThem(): void
    {
        $this->startSelling();
        $this->invoiceOf('100.00');

        $first = (new ReadinessRunner())->run()->toArray();

        $second = $this->invoiceOf('25.00');
        $this->assertGreaterThan(0, $second);

        $later = (new ReadinessRunner())->run()->toArray();

        [$exit, $output] = $this->compare([$this->file($first), $this->file($later)]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("data.aging_totals.{$this->customerId}: \"100.0000\" -> \"125.0000\"", $output);
        $this->assertStringContainsString('data.aging_total: "100.0000" -> "125.0000"', $output);
    }

    public function testRun_FlagsNothingForTwoRealReportsOfTheSameDatabase(): void
    {
        $this->startSelling();
        $this->invoiceOf('100.00');

        $first  = (new ReadinessRunner())->run()->toArray();
        $second = (new ReadinessRunner())->run()->toArray();

        [$exit, $output] = $this->compare([$this->file($first), $this->file($second)]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('RESULT: NO DIFFERENCE FLAGGED', $output);
    }

    public function testRun_ReadsNoDatabaseAndWritesNoRow(): void
    {
        $before = $this->file($this->report([$this->customerTies('100.0000')]));
        $after  = $this->file($this->report([$this->customerTies('120.0000')]));
        $print  = $this->databaseFingerprint();

        $this->compare([$before, $after]);

        $this->assertDatabaseUnchanged($print);
    }
}
