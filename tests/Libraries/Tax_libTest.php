<?php

namespace Tests\Libraries;

use App\Libraries\Tax_lib;
use App\Models\Enums\Rounding_mode;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Support\ParityFixture;

/**
 * The tax of one line, checked against amounts recorded in the reference system (fixtures T-01
 * to T-03): 15% not included, rounded half up to two decimals.
 */
class Tax_libTest extends CIUnitTestCase
{
    private const FIXTURES = ['T-01', 'T-02', 'T-03'];

    protected function setUp(): void
    {
        parent::setUp();

        bcscale(4);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function taxProvider(): array
    {
        $sets = [];

        foreach (self::FIXTURES as $fixture) {
            foreach (ParityFixture::cases($fixture, 'tax') as $name => [$case]) {
                $sets["{$fixture} {$name}"] = [$fixture, $case];
            }
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('taxProvider')]
    public function testGetTaxForAmount_MatchesTheTaxTheReferenceSystemComputed(string $fixture, array $case): void
    {
        $taxLib = (new ReflectionClass(Tax_lib::class))->newInstanceWithoutConstructor();

        $tax = $taxLib->get_tax_for_amount($case['inputs']['basis'], $case['inputs']['rate'], Rounding_mode::HALF_UP, 2);

        $this->assertSame(bcadd($case['expected']['tax'], '0', 2), bcadd($tax, '0', 2), $case['id']);
        $this->assertSame('exact', $case['classification']);
    }
}
