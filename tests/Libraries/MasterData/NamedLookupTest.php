<?php

namespace Tests\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\MasterData\LookupKind;
use App\Libraries\MasterData\NamedLookup;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * @internal
 */
final class NamedLookupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $db->table('customers')->update(['classification_id' => null]);
        $db->table('items')->update(['brand_id' => null]);

        foreach ($this->itemIds as $itemId) {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }

        $this->itemIds = [];

        $db->query('DELETE FROM ' . $db->prefixTable('customer_classifications'));
        $db->query('DELETE FROM ' . $db->prefixTable('item_brands'));
        $db->table('accounting_audit_log')->whereIn('entity_type', ['customer_classification', 'item_brand'])->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: LookupKind}>
     */
    public static function kindProvider(): array
    {
        return ['classification of customers' => [LookupKind::CustomerClassification], 'brand of items' => [LookupKind::ItemBrand]];
    }

    private function refusalOf(callable $action): ?AccountingError
    {
        try {
            $action();
        } catch (AccountingException $failure) {
            return $failure->error;
        }

        return null;
    }

    private function holdBy(LookupKind $kind, int $id): void
    {
        if ($kind === LookupKind::CustomerClassification) {
            $customerId = $this->insertCustomer();
            $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['classification_id' => $id]);

            return;
        }

        $itemId = $this->itemIds[] = $this->createTestItem();
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['brand_id' => $id]);
    }

    private function auditCount(LookupKind $kind, string $action): int
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => $kind->value, 'action' => 'lookup_' . $action])->countAllResults();
    }

    #[DataProvider('kindProvider')]
    public function testSave_CreatesAnEntryAndWritesItToTheAuditLog(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);

        $id = $lookup->save('  Gold  ', true, null, 1);

        $this->assertSame('Gold', $lookup->get($id)->name);
        $this->assertSame('1', (string) $lookup->get($id)->status);
        $this->assertSame(1, $this->auditCount($kind, 'created'));
    }

    #[DataProvider('kindProvider')]
    public function testSave_RejectsAnEmptyNameAndANameOverOneHundredCharacters(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);

        $this->assertSame(AccountingError::LookupNameInvalid, $this->refusalOf(fn () => $lookup->save('   ', true, null, 1)));
        $this->assertSame(AccountingError::LookupNameInvalid, $this->refusalOf(fn () => $lookup->save(str_repeat('a', 101), true, null, 1)));
        $this->assertSame([], $lookup->all());
    }

    #[DataProvider('kindProvider')]
    public function testSave_AcceptsANameOfExactlyOneHundredCharacters(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);

        $id = $lookup->save(str_repeat('ب', 100), true, null, 1);

        $this->assertSame(100, mb_strlen($lookup->get($id)->name));
    }

    #[DataProvider('kindProvider')]
    public function testSave_RejectsANameAnotherEntryHasAndAcceptsTheSameNameOnTheSameEntry(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);
        $first  = $lookup->save('Gold', true, null, 1);
        $lookup->save('Silver', true, null, 1);

        $this->assertSame(AccountingError::LookupNameTaken, $this->refusalOf(fn () => $lookup->save('Silver', true, $first, 1)));
        $this->assertSame($first, $lookup->save('Gold', false, $first, 1));
    }

    #[DataProvider('kindProvider')]
    public function testSave_ChangesTheNameAndTheStatusAndAuditsOnlyAChange(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);
        $id     = $lookup->save('Gold', true, null, 1);

        $lookup->save('Gold', true, $id, 1);
        $this->assertSame(0, $this->auditCount($kind, 'changed'));

        $lookup->save('Platinum', false, $id, 1);

        $this->assertSame(['Platinum', '0'], [$lookup->get($id)->name, (string) $lookup->get($id)->status]);
        $this->assertSame(1, $this->auditCount($kind, 'changed'));
    }

    #[DataProvider('kindProvider')]
    public function testSave_RejectsAnEntryThatDoesNotExist(LookupKind $kind): void
    {
        $this->assertSame(AccountingError::LookupNotFound, $this->refusalOf(fn () => (new NamedLookup($kind))->save('Gold', true, 987654, 1)));
    }

    #[DataProvider('kindProvider')]
    public function testSetStatus_DeactivatesAndActivatesAndWritesEachChangeOnce(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);
        $id     = $lookup->save('Gold', true, null, 1);

        $lookup->setStatus($id, false, 1);
        $lookup->setStatus($id, false, 1);
        $lookup->setStatus($id, true, 1);

        $this->assertSame('1', (string) $lookup->get($id)->status);
        $this->assertSame(1, $this->auditCount($kind, 'deactivated'));
        $this->assertSame(1, $this->auditCount($kind, 'activated'));
        $this->assertSame(AccountingError::LookupNotFound, $this->refusalOf(fn () => $lookup->setStatus(987654, true, 1)));
    }

    #[DataProvider('kindProvider')]
    public function testDelete_RemovesAnEntryNothingCarriesAndAuditsIt(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);
        $id     = $lookup->save('Gold', true, null, 1);

        $lookup->delete([$id], 1);

        $this->assertNull($lookup->get($id));
        $this->assertSame(1, $this->auditCount($kind, 'deleted'));
    }

    #[DataProvider('kindProvider')]
    public function testDelete_RejectsAnEntryARecordCarriesAndLeavesItAndTheOthersInPlace(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);
        $free   = $lookup->save('Free', true, null, 1);
        $held   = $lookup->save('Held', true, null, 1);
        $this->holdBy($kind, $held);

        $this->assertSame(AccountingError::LookupInUse, $this->refusalOf(fn () => $lookup->delete([$free, $held], 1)));
        $this->assertNotNull($lookup->get($free), 'The refusal undoes the removal of the free entry');
        $this->assertSame(1, (int) $lookup->get($held)->uses);
    }

    #[DataProvider('kindProvider')]
    public function testDelete_RejectsAnEntryThatDoesNotExist(LookupKind $kind): void
    {
        $this->assertSame(AccountingError::LookupNotFound, $this->refusalOf(fn () => (new NamedLookup($kind))->delete([987654], 1)));
    }

    #[DataProvider('kindProvider')]
    public function testOptions_OffersTheActiveEntriesOnly(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);
        $gold   = $lookup->save('Gold', true, null, 1);
        $lookup->save('Silver', false, null, 1);

        $this->assertSame([$gold => 'Gold'], $lookup->options());
        $this->assertCount(2, $lookup->all());
        $this->assertCount(1, $lookup->all(true));
    }

    #[DataProvider('kindProvider')]
    public function testMayBeGiven_AcceptsAnActiveEntryAndTheInactiveOneARecordHoldsAndRejectsTheRest(LookupKind $kind): void
    {
        $lookup   = new NamedLookup($kind);
        $active   = $lookup->save('Gold', true, null, 1);
        $inactive = $lookup->save('Silver', false, null, 1);

        $this->assertTrue($lookup->mayBeGiven($active, null));
        $this->assertFalse($lookup->mayBeGiven($inactive, null));
        $this->assertTrue($lookup->mayBeGiven($inactive, $inactive));
        $this->assertFalse($lookup->mayBeGiven(987654, 987654));
    }

    #[DataProvider('kindProvider')]
    public function testSearch_FindsByNameAndSortsAndCountsTheRecordsThatCarryTheEntry(LookupKind $kind): void
    {
        $lookup = new NamedLookup($kind);
        $lookup->save('Alpha', true, null, 1);
        $beta = $lookup->save('Beta', true, null, 1);
        $this->holdBy($kind, $beta);

        $found = $lookup->search('et', 'name', 'asc', 10, 0);

        $this->assertSame(1, $found['total']);
        $this->assertSame(['Beta', 1], [$found['rows'][0]->name, (int) $found['rows'][0]->uses]);

        $sorted = $lookup->search('', 'name', 'desc', 10, 0);

        $this->assertSame(['Beta', 'Alpha'], array_map(static fn (object $row): string => $row->name, $sorted['rows']));
        $this->assertCount(1, $lookup->search('', 'name', 'asc', 1, 1)['rows']);
    }
}
