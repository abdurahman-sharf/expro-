<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * The command that previews and posts the depreciation of a month: what it prints, that approving takes
 * the fingerprint of the preview, that a second run posts nothing new, and what its exit code says.
 *
 * @internal
 */
final class AccountingDepreciateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAssets();
        $this->resetBranches();
        $this->reinstallChart();
        $this->startAssets();
        $this->setConfigValue('accounting_enabled', '1');
    }

    protected function tearDown(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->releaseOpenTransactions();
        $this->clearAssets();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $params
     *
     * @return array{int, string}
     */
    private function depreciate(array $params = []): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('accounting:depreciate', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    private function fingerprintOf(string $output): string
    {
        preg_match('/FINGERPRINT: ([0-9a-f]{64})/', $output, $found);

        return $found[1] ?? '';
    }

    public function testRun_PreviewsWithoutWritingAndPrintsTheFingerprint(): void
    {
        $this->enterAsset(['code' => 'DESK']);

        [$exit, $output] = $this->depreciate(['month' => '2026-02']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('DEPRECIATION of 2026-02', $output);
        $this->assertStringContainsString('DESK Office desk: 1000.0000', $output);
        $this->assertStringContainsString('Post it with --approve', $output);
        $this->assertSame(64, strlen($this->fingerprintOf($output)));
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testRun_PostsWhatThePreviewShowedWhenGivenItsFingerprint(): void
    {
        $this->enterAsset(['code' => 'DESK']);

        [, $preview] = $this->depreciate(['month' => '2026-02']);
        [$exit, $output] = $this->depreciate(['month' => '2026-02', 'approve' => $this->fingerprintOf($preview)]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 run(s) posted for 2026-02', $output);
        $this->assertSame(1, $this->countRows('asset_depreciation_runs'));
        $this->assertSame('-1000.0000', $this->accountNet($this->accountId('accumulated_depreciation')));
    }

    public function testRun_PostsNothingNewWhenItIsRunAgainWithTheSameFingerprint(): void
    {
        $this->enterAsset(['code' => 'DESK']);

        [, $preview] = $this->depreciate(['month' => '2026-02']);
        $fingerprint = $this->fingerprintOf($preview);

        $this->depreciate(['month' => '2026-02', 'approve' => $fingerprint]);
        [$exit, $output] = $this->depreciate(['month' => '2026-02', 'approve' => $fingerprint]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('depreciation', strtolower($output));
        $this->assertSame(1, $this->countRows('asset_depreciation_runs'));
    }

    public function testRun_RefusesAFingerprintOfAnotherPreview(): void
    {
        $this->enterAsset(['code' => 'DESK']);

        [$exit, $output] = $this->depreciate(['month' => '2026-02', 'approve' => str_repeat('a', 64)]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('preview', strtolower($output));
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testRun_RefusesAClosedPeriodByNameAndExitsWithOne(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $this->closeThrough('2026-02-28');

        [, $preview] = $this->depreciate(['month' => '2026-02']);
        [$exit, $output] = $this->depreciate(['month' => '2026-02', 'approve' => $this->fingerprintOf($preview)]);

        $this->assertStringContainsString('STOPPED', $preview);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('2026-02', $output);
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testRun_OnlyRunsTheBranchGiven(): void
    {
        $second = $this->createBranch('T2', 'Second branch');

        $this->enterAsset(['code' => 'ONE']);
        $this->enterAsset(['code' => 'TWO', 'name' => 'Chair', 'branch_id' => $second, 'funding' => 'payable', 'treasury_id' => '']);

        [, $preview] = $this->depreciate(['month' => '2026-02', 'branch' => (string) $second]);
        $this->depreciate(['month' => '2026-02', 'branch' => (string) $second, 'approve' => $this->fingerprintOf($preview)]);

        $this->assertStringNotContainsString('ONE', $preview);
        $this->assertSame([$second], array_map(static fn (object $run): int => (int) $run->branch_id, $this->accountingDb()->table('asset_depreciation_runs')->get()->getResult()));
    }

    public function testRun_RefusesAnOptionThatIsNotValidWithSeven(): void
    {
        foreach ([['month' => '2026-13'], ['month' => '2026-02', 'branch' => 'abc'], ['month' => '2026-02', 'approve' => 'xyz']] as $params) {
            [$exit] = $this->depreciate($params);

            $this->assertSame(7, $exit, json_encode($params));
        }
    }

    public function testRun_DoesNothingAndExitsWithZeroWhileAccountingIsOff(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $this->setConfigValue('accounting_enabled', '0');

        [$exit, $output] = $this->depreciate(['month' => '2026-02']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Accounting is off', $output);
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testRun_SaysNothingCanBePostedWhenNothingIsDue(): void
    {
        $this->enterAsset(['code' => 'DESK']);

        [$exit, $output] = $this->depreciate(['month' => '2026-01']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing can be posted', $output);
    }
}
