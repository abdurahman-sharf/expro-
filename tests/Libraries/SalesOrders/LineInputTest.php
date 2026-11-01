<?php

namespace Tests\Libraries\SalesOrders;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\SalesOrders\LineInput;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class LineInputTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $itemId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->itemId = $this->createTestItem(HAS_NO_STOCK);
    }

    /**
     * @param list<array<string, mixed>>|mixed $lines
     *
     * @return list<array<string, mixed>>
     */
    private function normalise(mixed $lines): array
    {
        return LineInput::normalise($lines, 1, $this->accountingDb(), AccountingError::SalesOrderNoLines, AccountingError::SalesOrderLineInvalid);
    }

    private function assertRefused(AccountingError $error, mixed $lines): void
    {
        try {
            $this->normalise($lines);
            $this->fail('The lines should have been refused.');
        } catch (AccountingException $refusal) {
            $this->assertSame($error, $refusal->error);
        }
    }

    public function testNormalise_PutsALineInTheShapeItIsStoredIn(): void
    {
        $lines = $this->normalise([['item_id' => (string) $this->itemId, 'quantity' => '2.5', 'unit_price' => '10', 'discount' => '', 'description' => ' Custom ']]);

        $this->assertSame([['item_id' => $this->itemId, 'description' => 'Custom', 'quantity' => '2.500', 'unit_price' => '10.00', 'discount' => '0.00', 'discount_type' => 0, 'item_location' => 1]], $lines);
    }

    public function testNormalise_RequiresAtLeastOneLineAndRefusesMoreThanTheLimit(): void
    {
        $line = ['item_id' => $this->itemId, 'quantity' => '1', 'unit_price' => '1'];

        $this->assertRefused(AccountingError::SalesOrderNoLines, []);
        $this->assertRefused(AccountingError::SalesOrderNoLines, null);
        $this->assertRefused(AccountingError::SalesOrderLineInvalid, array_fill(0, LineInput::MAX_LINES + 1, $line));
        $this->assertCount(LineInput::MAX_LINES, $this->normalise(array_fill(0, LineInput::MAX_LINES, $line)));
    }

    public function testNormalise_RefusesAFigureThatIsNotANumberOrIsOutOfRange(): void
    {
        $base = ['item_id' => $this->itemId, 'quantity' => '1', 'unit_price' => '1'];

        foreach ([
            ['quantity' => '0'], ['quantity' => '-1'], ['quantity' => 'abc'], ['quantity' => '1.2345'], ['quantity' => ''],
            ['unit_price' => '-1'], ['unit_price' => '1.234'], ['unit_price' => 'x'],
            ['discount' => '-5'], ['discount' => '100.01'], ['discount' => 'x'],
            ['discount_type' => 1, 'quantity' => '2', 'unit_price' => '10', 'discount' => '20.01'],
            ['discount_type' => 7],
            ['description' => str_repeat('x', LineInput::DESCRIPTION_MAX + 1)],
            ['item_id' => 0],
        ] as $change) {
            $this->assertRefused(AccountingError::SalesOrderLineInvalid, [[...$base, ...$change]]);
        }

        $this->assertRefused(AccountingError::SalesOrderLineInvalid, ['not a line']);
    }

    public function testNormalise_AcceptsTheLimitsOfTheFigures(): void
    {
        $lines = $this->normalise([
            ['item_id' => $this->itemId, 'quantity' => '0.001', 'unit_price' => '0', 'discount' => '100', 'discount_type' => 0],
            ['item_id' => $this->itemId, 'quantity' => '2', 'unit_price' => '10', 'discount' => '20.00', 'discount_type' => 1],
            ['item_id' => $this->itemId, 'quantity' => 3, 'unit_price' => 1.5],
        ]);

        $this->assertSame(['0.001', '2.000', '3.000'], array_column($lines, 'quantity'));
        $this->assertSame(['100.00', '20.00', '0.00'], array_column($lines, 'discount'));
        $this->assertSame(['1.50'], [$lines[2]['unit_price']]);
    }

    public function testNormalise_RefusesAnItemThatDoesNotExistOrWasDeleted(): void
    {
        $deleted = $this->createTestItem(HAS_NO_STOCK);
        $this->accountingDb()->table('items')->where('item_id', $deleted)->update(['deleted' => 1]);

        $this->assertRefused(AccountingError::SalesOrderLineInvalid, [['item_id' => 99999999, 'quantity' => '1', 'unit_price' => '1']]);
        $this->assertRefused(AccountingError::SalesOrderLineInvalid, [['item_id' => $this->itemId, 'quantity' => '1', 'unit_price' => '1'], ['item_id' => $deleted, 'quantity' => '1', 'unit_price' => '1']]);
    }

    public function testNormalise_SaysWhichLineIsWrong(): void
    {
        try {
            $this->normalise([['item_id' => $this->itemId, 'quantity' => '1', 'unit_price' => '1'], ['item_id' => $this->itemId, 'quantity' => '0', 'unit_price' => '1']]);
            $this->fail('The second line is wrong.');
        } catch (AccountingException $refusal) {
            $this->assertSame(2, $refusal->context['line']);
        }
    }
}
