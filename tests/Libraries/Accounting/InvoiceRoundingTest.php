<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\InvoiceRounding;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ParityFixture;

/**
 * Fixture T-04: six invoices entered in the reference system. Four come out the same here. In
 * the other two the total fell exactly half a halala between two amounts and the reference
 * system rounded down where this rounds up, so the results differ by one halala. Those two are
 * pinned as differences: they are not matches, and a change in either direction fails a test.
 */
class InvoiceRoundingTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function exactProvider(): array
    {
        return self::dataSets('exact');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function oneHalalaProvider(): array
    {
        return self::dataSets('one_halala');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    private static function dataSets(string $classification): array
    {
        return array_filter(ParityFixture::cases('T-04', 'rounding'), static fn (array $set): bool => $set[0]['classification'] === $classification);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('exactProvider')]
    public function testSplit_GivesTheTotalAndRevenueTheReferenceSystemStored(array $case): void
    {
        $split = InvoiceRounding::split($case['inputs']['net'], $case['inputs']['tax']);

        $this->assertSame(bcadd($case['reference']['total'], '0', 4), $split['total'], $case['id']);
        $this->assertSame(bcadd($case['reference']['revenue'], '0', 4), $split['revenue'], $case['id']);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('oneHalalaProvider')]
    public function testSplit_DiffersFromTheReferenceSystemByExactlyOneHalalaOnADisputedTie(array $case): void
    {
        $split = InvoiceRounding::split($case['inputs']['net'], $case['inputs']['tax']);

        $this->assertSame(bcadd($case['expected']['total'], '0', 4), $split['total'], $case['id']);
        $this->assertSame(bcadd($case['expected']['revenue'], '0', 4), $split['revenue'], $case['id']);
        $this->assertNotSame(bcadd($case['reference']['total'], '0', 4), $split['total'], "{$case['id']} must not be counted as matching");
        $this->assertSame('0.0100', bcsub($split['total'], $case['reference']['total'], 4), $case['id']);
        $this->assertSame('0.0100', bcsub($split['revenue'], $case['reference']['revenue'], 4), $case['id']);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('oneHalalaProvider')]
    public function testSplit_OnlyDiffersWhereTheTotalFallsExactlyBetweenTwoHalalas(array $case): void
    {
        $exact = bcadd($case['inputs']['net'], $case['inputs']['tax'], 4);

        $this->assertSame('5', substr($exact, -2, 1), $case['id']);
        $this->assertSame('0', substr($exact, -1), $case['id']);
    }

    public function testFixture_RecordsFourExactCasesAndTwoThatDifferByOneHalala(): void
    {
        $this->assertCount(4, self::exactProvider());
        $this->assertCount(2, self::oneHalalaProvider());
        $this->assertCount(6, ParityFixture::cases('T-04', 'rounding'));
    }

    #[DataProvider('exactProvider')]
    public function testSplit_KeepsTheTaxUnroundedAndBalancesTheJournal(array $case): void
    {
        $split = InvoiceRounding::split($case['inputs']['net'], $case['inputs']['tax']);

        $this->assertSame(bcadd($case['inputs']['tax'], '0', 4), $split['tax']);
        $this->assertSame($split['total'], bcadd($split['revenue'], $split['tax'], 4));
    }

    public function testSplit_LeavesAWholeHalalaAlone(): void
    {
        $split = InvoiceRounding::split('100.00', '15.00');

        $this->assertSame('115.0000', $split['total']);
        $this->assertSame('100.0000', $split['revenue']);
    }

    public function testRoundToCents_RoundsByTheThirdAndFourthDecimals(): void
    {
        $this->assertSame('11.6100', InvoiceRounding::roundToCents('11.6149'));
        $this->assertSame('11.6200', InvoiceRounding::roundToCents('11.6150'));
        $this->assertSame('0.0000', InvoiceRounding::roundToCents('0.0049'));
    }
}
