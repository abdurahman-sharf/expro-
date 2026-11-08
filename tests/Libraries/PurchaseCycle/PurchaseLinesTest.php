<?php

namespace Tests\Libraries\PurchaseCycle;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\PurchaseCycle\PurchaseLines;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class PurchaseLinesTest extends CIUnitTestCase
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

        $this->itemId = $this->createTestItem();
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidLineProvider(): array
    {
        return [
            'quantity zero'              => [['quantity' => '0', 'unit_price' => '1']],
            'negative quantity'          => [['quantity' => '-1', 'unit_price' => '1']],
            'quantity with four decimals' => [['quantity' => '1.0001', 'unit_price' => '1']],
            'quantity of 13 digits'      => [['quantity' => '1000000000000', 'unit_price' => '1']],
            'quantity as text'           => [['quantity' => 'ten', 'unit_price' => '1']],
            'quantity as a list'         => [['quantity' => ['1'], 'unit_price' => '1']],
            'negative price'             => [['quantity' => '1', 'unit_price' => '-1']],
            'price with three decimals'  => [['quantity' => '1', 'unit_price' => '1.001']],
            'price in exponent form'     => [['quantity' => '1', 'unit_price' => '1e3']],
            'a note of 256 characters'   => [['quantity' => '1', 'unit_price' => '1', 'description' => 'x']],
        ];
    }

    /**
     * @param array<string, mixed> $line
     */
    #[DataProvider('invalidLineProvider')]
    public function testNormalise_RejectsALineThatIsNotValid(array $line): void
    {
        if (($line['description'] ?? '') === 'x') {
            $line['description'] = str_repeat('x', 256);
        }

        try {
            PurchaseLines::normalise([[...$line, 'item_id' => $this->itemId]], $this->accountingDb());
            $this->fail('The line should have been rejected.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::PurchaseDocumentLineInvalid, $refusal->error);
            $this->assertSame(1, $refusal->context['line']);
        }
    }

    public function testNormalise_PutsTheFiguresInTheScaleTheyAreStoredInAndTrimsTheNote(): void
    {
        $lines = PurchaseLines::normalise([
            ['item_id' => (string) $this->itemId, 'quantity' => '2.5', 'unit_price' => '3', 'description' => '  a note  '],
            ['item_id' => $this->itemId, 'quantity' => 4, 'unit_price' => '', 'description' => null],
            ['item_id' => $this->itemId, 'quantity' => '0.001', 'unit_price' => 0.5],
        ], $this->accountingDb());

        $this->assertSame(['2.500', '4.000', '0.001'], array_column($lines, 'quantity'));
        $this->assertSame(['3.00', '0.00', '0.50'], array_column($lines, 'unit_price'));
        $this->assertSame(['a note', '', ''], array_column($lines, 'description'));
        $this->assertSame([$this->itemId, $this->itemId, $this->itemId], array_column($lines, 'item_id'));
    }

    public function testNormalise_ReportsTheNumberOfTheLineThatIsNotValid(): void
    {
        try {
            PurchaseLines::normalise([['item_id' => $this->itemId, 'quantity' => '1'], ['item_id' => $this->itemId, 'quantity' => '0']], $this->accountingDb());
            $this->fail('The second line should have been rejected.');
        } catch (AccountingException $refusal) {
            $this->assertSame(2, $refusal->context['line']);
        }
    }

    public function testNormalise_RejectsAnItemThatWasDeleted(): void
    {
        $this->accountingDb()->table('items')->where('item_id', $this->itemId)->update(['deleted' => 1]);

        try {
            PurchaseLines::normalise([['item_id' => $this->itemId, 'quantity' => '1']], $this->accountingDb());
            $this->fail('A deleted item is not a line.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::PurchaseDocumentLineInvalid, $refusal->error);
        }
    }

    public function testNormalise_RejectsAnEmptyListAndTooManyLines(): void
    {
        foreach ([[], 'x', null] as $raw) {
            try {
                PurchaseLines::normalise($raw, $this->accountingDb());
                $this->fail('A document needs lines.');
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::PurchaseDocumentNoLines, $refusal->error);
            }
        }

        try {
            PurchaseLines::normalise(array_fill(0, PurchaseLines::MAX_LINES + 1, ['item_id' => $this->itemId, 'quantity' => '1']), $this->accountingDb());
            $this->fail('Too many lines.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::PurchaseDocumentLineInvalid, $refusal->error);
            $this->assertSame(PurchaseLines::MAX_LINES, $refusal->context['max']);
        }
    }
}
