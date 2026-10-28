<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class ParityFixtureTest extends CIUnitTestCase
{
    private const DOCS            = __DIR__ . '/../../docs/accounting';
    private const TESTS           = __DIR__ . '/..';
    private const FIRST_OBSERVED  = 26;
    private const LAST            = 64;
    private const ABSENCES        = ['table', 'column', 'class', 'route'];

    /**
     * @return array<string, array{string}>
     */
    public static function fixtureProvider(): array
    {
        $sets = [];

        foreach (ParityFixture::ids() as $id) {
            $sets[$id] = [$id];
        }

        return $sets;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function observedFixtureProvider(): array
    {
        return array_filter(self::fixtureProvider(), static fn (array $set): bool => (int) substr($set[0], 2) >= self::FIRST_OBSERVED);
    }

    public function testIds_ListsEveryFixtureFromT01OnwardsInOrderWithoutAGap(): void
    {
        $this->assertSame(array_map(static fn (int $number): string => sprintf('T-%02d', $number), range(1, self::LAST)), ParityFixture::ids());
    }

    #[DataProvider('fixtureProvider')]
    public function testLoad_ReadsAFixtureThatNamesItselfAndItsRecordingDate(string $id): void
    {
        $fixture = ParityFixture::load($id);

        $this->assertSame($id, $fixture['id']);
        $this->assertNotSame('', trim($fixture['title']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $fixture['recorded']);
        $this->assertNotEmpty($fixture['cases']);
    }

    #[DataProvider('fixtureProvider')]
    public function testEvidence_IsAnIdOrAnExplainedGap(string $id): void
    {
        foreach (ParityFixture::load($id)['evidence'] as $key => $evidence) {
            $this->assertNotSame('', trim($evidence['note']), "{$id} {$key} needs a note");

            if ($evidence['id'] !== null) {
                $this->assertMatchesRegularExpression(ParityFixture::EVIDENCE_PATTERN, $evidence['id'], "{$id} {$key}");
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function recordedFixtureProvider(): array
    {
        return array_filter(self::fixtureProvider(), static fn (array $set): bool => array_filter(array_column(ParityFixture::load($set[0])['evidence'], 'id')) !== []);
    }

    #[DataProvider('recordedFixtureProvider')]
    public function testEvidence_IsRecordedInTheEvidenceDocuments(string $id): void
    {
        $documents = '';

        foreach (glob(self::DOCS . '/{*,*/*}.md', GLOB_BRACE) ?: [] as $path) {
            $documents .= (string) file_get_contents($path);
        }

        foreach (ParityFixture::load($id)['evidence'] as $key => $evidence) {
            if ($evidence['id'] !== null) {
                $this->assertStringContainsString($evidence['id'], $documents, "{$id} {$key} is not in the evidence documents");
            }
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testCases_EachNameTheirEvidenceInputsAndOutputs(string $id): void
    {
        $fixture = ParityFixture::load($id);

        foreach ($fixture['cases'] as $case) {
            $this->assertNotSame('', $case['id']);
            $this->assertNotEmpty($case['evidence'], "{$id} {$case['id']} cites no evidence");

            foreach ($case['evidence'] as $key) {
                $this->assertArrayHasKey($key, $fixture['evidence'], "{$id} {$case['id']} cites {$key}");
            }

            if ($case['kind'] === 'cost_sequence') {
                $this->assertNotEmpty($case['steps']);

                foreach ($case['steps'] as $step) {
                    $this->assertContains($step['classification'], ParityFixture::CLASSIFICATIONS, "{$id} {$step['name']}");
                    $this->assertArrayHasKey('reference', $step);
                    $this->assertArrayHasKey('expected', $step);
                }

                continue;
            }

            $this->assertContains($case['classification'], ParityFixture::CLASSIFICATIONS, "{$id} {$case['id']}");
            $this->assertArrayHasKey('inputs', $case);
            $this->assertArrayHasKey('expected', $case);
        }
    }

    public function testCases_KeepsTheDataSetsOfOneKindUnderTheirCaseIds(): void
    {
        $lines = ParityFixture::cases('T-01', 'line');

        $this->assertArrayHasKey('line-4x75-10pct', $lines);
        $this->assertSame('270.00', $lines['line-4x75-10pct'][0]['expected']['line_total']);
        $this->assertArrayNotHasKey('tax-on-270', $lines);
    }

    public function testEvidenceOf_ListsTheIdsACaseCitesAndSkipsTheOnesNeverKept(): void
    {
        $purchase = ParityFixture::cases('T-01', 'line')['line-purchase-20x40'][0];
        $invoice  = ParityFixture::cases('T-01', 'line')['line-4x75-10pct'][0];

        $this->assertSame([], ParityFixture::evidenceOf('T-01', $purchase));
        $this->assertSame([ParityFixture::load('T-01')['evidence']['invoice_000002']['id']], ParityFixture::evidenceOf('T-01', $invoice));
    }

    public function testDifferences_IsEmptyWhenBothSidesSayTheSameInNumbersAndInAnyOrderOfLines(): void
    {
        $reference = ['journal' => [['role' => 'customer', 'debit' => '115.00', 'credit' => '0'], ['role' => 'sales_revenue', 'debit' => '0', 'credit' => '115']], 'state' => 'open'];
        $expected  = ['journal' => [['role' => 'sales_revenue', 'debit' => '0.0000', 'credit' => '115.0'], ['role' => 'customer', 'debit' => '115', 'credit' => '0.00']], 'state' => 'open'];

        $this->assertSame([], ParityFixture::differences($reference, $expected));
    }

    public function testDifferences_NamesThePathOfWhatDiffersAtTheLastStoredDecimal(): void
    {
        $reference = ['journal' => [['role' => 'sales_revenue', 'debit' => '0', 'credit' => '343.8174725']]];
        $expected  = ['journal' => [['role' => 'sales_revenue', 'debit' => '0', 'credit' => '343.8170']]];

        $this->assertSame(['journal.0.credit'], ParityFixture::differences($reference, $expected));
    }

    public function testDifferences_CountsALineOneSideHasAndTheOtherLacks(): void
    {
        $line      = ['role' => 'customer', 'debit' => '10', 'credit' => '0'];
        $reference = ['journal' => [$line, ['role' => 'sales_revenue', 'debit' => '0', 'credit' => '10']]];

        $this->assertSame(['journal'], ParityFixture::differences($reference, ['journal' => [$line]]));
        $this->assertSame(['journal'], ParityFixture::differences(['journal' => [$line]], $reference));
    }

    public function testDifferences_SkipsTheKeysThatOnlyExplainAndFlagsAKeyTheReferenceLacks(): void
    {
        $this->assertSame([], ParityFixture::differences(['result' => 'accepted'], ['result' => 'accepted', 'why' => 'because', 'note' => 'n', 'reason' => 'r', 'detail' => ['x' => 1]]));
        $this->assertSame(['result'], ParityFixture::differences(['other' => 'x'], ['result' => 'accepted']));
        $this->assertSame(['result'], ParityFixture::differences(['result' => 'accepted'], ['result' => 'refused']));
        $this->assertSame(['result'], ParityFixture::differences(['result' => false], ['result' => '0']));
    }

    #[DataProvider('observedFixtureProvider')]
    public function testLoad_NamesTheObservationItWasMadeFromAndTheFactsItRests(string $id): void
    {
        $fixture = ParityFixture::load($id);

        $this->assertContains($fixture['observation']['batch'], ['d1', 'd2', 'b'], $id);
        $this->assertNotEmpty($fixture['observation']['scenarios'], $id);

        foreach ($fixture['observation']['scenarios'] as $scenario) {
            $this->assertContains($scenario, ParityFixture::observedScenarios($fixture['observation']['batch']), "{$id} cites {$scenario}");
        }

        foreach ($fixture['evidence'] as $key => $evidence) {
            $this->assertNotEmpty($evidence['observation'], "{$id} {$key} cites no fact of the observation");

            foreach ($evidence['observation'] as $fact) {
                $this->assertContains($fact, ParityFixture::observedFacts(), "{$id} {$key} cites {$fact}");
            }
        }
    }

    #[DataProvider('observedFixtureProvider')]
    public function testCases_SayWhatTheirClassificationClaimsAboutTheirTwoSides(string $id): void
    {
        foreach (ParityFixture::load($id)['cases'] as $case) {
            $this->assertArrayHasKey('reference', $case, "{$id} {$case['id']}");

            $differences = ParityFixture::differences($case['reference'], $case['expected']);

            match ($case['classification']) {
                'exact'                            => $this->assertSame([], $differences, "{$id} {$case['id']} is exact but its sides differ"),
                'differs', 'deliberate_difference' => $this->assertNotSame([], $differences, "{$id} {$case['id']} differs but its sides agree"),
                default                            => $this->assertSame('not_implemented', $case['expected']['status'] ?? null, "{$id} {$case['id']}"),
            };
        }
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function observedDifferenceProvider(): array
    {
        $sets = [];

        foreach (array_keys(self::observedFixtureProvider()) as $id) {
            foreach (ParityFixture::load($id)['cases'] as $case) {
                if ($case['classification'] !== 'exact') {
                    $sets["{$id} {$case['id']}"] = [$id, $case];
                }
            }
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('observedDifferenceProvider')]
    public function testCases_EveryDifferenceAndEveryGapGivesItsReason(string $id, array $case): void
    {
        $this->assertNotSame('', trim($case['reason'] ?? ''), "{$id} {$case['id']} needs a reason");
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function observedGapProvider(): array
    {
        $sets = [];

        foreach (array_keys(self::observedFixtureProvider()) as $id) {
            foreach (ParityFixture::load($id)['cases'] as $case) {
                if ($case['classification'] === 'not_implemented') {
                    $sets["{$id} {$case['id']}"] = [$id, $case];
                }
            }
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('observedGapProvider')]
    public function testCases_EveryGapNamesItsWorkPackageAndWhatItChecksIsAbsent(string $id, array $case): void
    {
        $backlog = (string) file_get_contents(self::DOCS . '/daftra-parity/backlog.md');

        $this->assertMatchesRegularExpression('/^WP\d+$/', $case['work_package'], "{$id} {$case['id']}");
        $this->assertTrue(str_contains($backlog, "**{$case['work_package']}**"), "{$id} {$case['id']} names a package the backlog does not have");
        $this->assertNotEmpty($case['expected']['absent'], "{$id} {$case['id']} checks nothing");

        foreach ($case['expected']['absent'] as $absence) {
            $this->assertContains($absence['kind'], self::ABSENCES, "{$id} {$case['id']}");
            $this->assertNotSame('', trim($absence['name'] ?? $absence['pattern'] ?? ''), "{$id} {$case['id']}");

            if ($absence['kind'] === 'column') {
                $this->assertNotSame('', trim($absence['table'] ?? ''), "{$id} {$case['id']}");
            }
        }
    }

    #[DataProvider('observedFixtureProvider')]
    public function testCases_EachIsAssertedByATestThatNamesIt(string $id): void
    {
        $sources = '';
        $files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::TESTS, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && $file->getBasename() !== 'ParityFixtureTest.php') {
                $sources .= (string) file_get_contents($file->getPathname());
            }
        }

        $this->assertFileExists(self::TESTS . '/Support/ParityNotImplementedTest.php');

        foreach (ParityFixture::load($id)['cases'] as $case) {
            if ($case['classification'] !== 'not_implemented') {
                $this->assertTrue(str_contains($sources, "'{$case['id']}'"), "{$id} {$case['id']} is not asserted by any test");
            }
        }
    }

    public function testLoad_RefusesAFixtureThatDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);

        ParityFixture::load('T-99');
    }
}
