<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ParityFixtureTest extends CIUnitTestCase
{
    private const CLASSIFICATIONS = ['exact', 'one_halala', 'differs'];
    private const DOCS            = __DIR__ . '/../../docs/accounting';

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

    public function testIds_ListsFixturesT01ToT13(): void
    {
        $this->assertSame(['T-01', 'T-02', 'T-03', 'T-04', 'T-05', 'T-06', 'T-07', 'T-08', 'T-09', 'T-10', 'T-11', 'T-12', 'T-13'], ParityFixture::ids());
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

    #[DataProvider('fixtureProvider')]
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
                    $this->assertContains($step['classification'], self::CLASSIFICATIONS, "{$id} {$step['name']}");
                    $this->assertArrayHasKey('reference', $step);
                    $this->assertArrayHasKey('expected', $step);
                }

                continue;
            }

            $this->assertContains($case['classification'], self::CLASSIFICATIONS, "{$id} {$case['id']}");
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

    public function testLoad_RefusesAFixtureThatDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);

        ParityFixture::load('T-99');
    }
}
