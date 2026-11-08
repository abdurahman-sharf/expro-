<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingFailureLog;
use App\Libraries\Accounting\ItemRoutedAccount;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RoutingType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;

class RoutingResolverTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private RoutingResolver $resolver;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->resolver = new RoutingResolver();
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * Every row against every type, so that a type added later is refused until someone decides
     * what posting does with it.
     *
     * @return array<string, array{RoutingKey, RoutingType}>
     */
    public static function everyRowAndTypeProvider(): array
    {
        $sets = [];

        foreach (RoutingKey::cases() as $key) {
            foreach (RoutingType::cases() as $type) {
                $sets["{$key->value} as {$type->value}"] = [$key, $type];
            }
        }

        return $sets;
    }

    private function setRouting(RoutingKey $key, RoutingType $type): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type->value]);
    }

    private function entityIdFor(RoutingKey $key): ?int
    {
        $kind = $key->entityKind();

        if ($kind === null) {
            return null;
        }

        $entities = new EntityAccountService();

        return match ($kind) {
            EntityAccountService::CUSTOMER       => $this->entityWithAccount($entities, $kind, $this->insertCustomer(), 'Omar Khaled'),
            EntityAccountService::SUPPLIER       => $this->entityWithAccount($entities, $kind, $this->insertSupplier(), 'Gulf Supplies'),
            EntityAccountService::STOCK_LOCATION => $this->entityWithAccount($entities, $kind, $this->insertStockLocation('Back store'), 'Back store'),
            default                              => $this->entityWithAccount($entities, $kind, 7, 'Branch till'),
        };
    }

    private function entityWithAccount(EntityAccountService $entities, string $kind, int $entityId, string $name): int
    {
        $entities->ensure($kind, $entityId, $name);

        return $entityId;
    }

    #[DataProvider('everyRowAndTypeProvider')]
    public function testAccountFor_AppliesAnImplementedTypeAndRefusesEveryOtherOne(RoutingKey $key, RoutingType $type): void
    {
        $entityId = $this->entityIdFor($key);
        $this->setRouting($key, $type);

        if (! $key->applies($type) || ! in_array($type, $key->allowedTypes(), true)) {
            try {
                $this->resolver->accountFor($key, $entityId);
                $this->fail("{$key->value} saved as {$type->value} must not be resolved.");
            } catch (PostingException $failure) {
                $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
                $this->assertSame($key->value, $failure->context['routing_key']);
                $this->assertSame($type->value, $failure->context['routing_type']);
            }

            return;
        }

        if ($type === RoutingType::Auto && $key->defaultSystemKey() === null) {
            $this->assertUnusable($key);

            return;
        }

        $accountId = $this->resolver->accountFor($key, $entityId);

        $type === RoutingType::Cancelled ? $this->assertNull($accountId) : $this->assertIsInt($accountId);
    }

    public function testAccountFor_ReturnsTheAccountOfTheRowWhenItIsAutomatic(): void
    {
        $this->assertSame($this->accountId('sales_revenue'), $this->resolver->accountFor(RoutingKey::SalesRevenue));
        $this->assertSame($this->accountId('cogs'), $this->resolver->accountFor(RoutingKey::CostOfGoodsSold));
    }

    public function testAccountFor_ReturnsNothingForACancelledRowSoNoLineIsWritten(): void
    {
        $this->assertNull($this->resolver->accountFor(RoutingKey::SalesDiscount));
    }

    public function testAccountFor_ReturnsTheSubAccountOfTheEntityForAnEntityRow(): void
    {
        $customerId = $this->insertCustomer();
        $accountId  = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->assertSame($accountId, $this->resolver->accountFor(RoutingKey::Customers, $customerId));
    }

    public function testAccountFor_RefusesAnEntityRowWithoutAnEntityOrWithoutItsAccount(): void
    {
        $customerId = $this->insertCustomer();

        foreach ([null, $customerId] as $entityId) {
            try {
                $this->resolver->accountFor(RoutingKey::Customers, $entityId);
                $this->fail('An entity row needs the sub-account of an entity.');
            } catch (PostingException $failure) {
                $this->assertSame(PostingError::EntityAccountMissing, $failure->error);
            }
        }
    }

    public function testAccountFor_RefusesARowWhoseAccountIsMissingAGroupOrInactive(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['account_id' => null]);
        $this->assertUnusable(RoutingKey::SalesRevenue);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['account_id' => $this->accountIdByCode('41')]);
        $this->assertUnusable(RoutingKey::SalesRevenue);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['account_id' => $this->accountId('sales_revenue')]);
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'sales_revenue')->update(['active' => 0]);
        $this->assertUnusable(RoutingKey::SalesRevenue);
    }

    private function assertUnusable(RoutingKey $key): void
    {
        try {
            $this->resolver->accountFor($key);
            $this->fail("{$key->value} should have been refused.");
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::RoutingAccountUnusable, $failure->error);
        }
    }

    public function testAccountFor_RefusesATypeTheRowDoesNotOfferEvenWhenItIsImplemented(): void
    {
        $this->setRouting(RoutingKey::RetainedEarnings, RoutingType::Cancelled);

        $this->expectException(PostingException::class);

        $this->resolver->accountFor(RoutingKey::RetainedEarnings);
    }

    public function testAccountFor_StopsABuilderBeforeAnyJournalIsWrittenAndTheFailureIsRecorded(): void
    {
        $this->setRouting(RoutingKey::Customers, RoutingType::PerItem);
        $journals = $this->countRows('accounting_journals');

        try {
            $this->resolver->accountFor(RoutingKey::Customers, $this->entityIdFor(RoutingKey::Customers));
            $this->fail('The routing type should have stopped the posting.');
        } catch (PostingException $failure) {
            (new PostingFailureLog())->recordUnbuilt('sale', 77, 1, JournalEntryType::Revenue, $failure, 1, 1);
        }

        $row = $this->accountingDb()->table('accounting_posting_failures')->get()->getRow();

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('routing_type_unsupported', $row->error_code);
        $this->assertSame('sale', $row->source_type);
        $this->assertSame('77', (string) $row->source_id);
        $this->assertSame('revenue', $row->entry_type);
        $this->assertSame('sales.customers', json_decode($row->context, true)['routing_key']);
    }

    /**
     * Posting code must not read the routing table itself, because it would take the account of a
     * row without looking at its type. The files below are the ones that read it today, each for a
     * reason that is not choosing an account for a journal line: seeding, the screen, the
     * readiness checks, the entity main account, the opening balance exclusions, the chart and the
     * inventory valuation report, which names the main stock account to tie its total to, and the
     * tax report, which names the accounts the two kinds of tax are routed to.
     */
    public function testNoPostingCodeReadsTheRoutingTableExceptThroughTheResolver(): void
    {
        $allowed = [
            'Libraries/Accounting/AccountService.php',
            'Libraries/Accounting/AccountingSettings.php',
            'Libraries/Accounting/BankAccountPlacement.php',
            'Libraries/Accounting/DefaultChart.php',
            'Libraries/Accounting/EntityAccountService.php',
            'Libraries/Accounting/FamilyReports/CashDocumentRows.php',
            'Libraries/Accounting/FamilyReports/ControlAccounts.php',
            'Libraries/Accounting/InventoryValuation.php',
            'Libraries/Accounting/OpeningBalanceService.php',
            'Libraries/Accounting/Readiness/RoutingChecks.php',
            'Libraries/Accounting/RoutingResolver.php',
            'Libraries/Accounting/RoutingService.php',
            'Libraries/Accounting/ShippingCompanies.php',
            'Libraries/Accounting/TaxDefinitions.php',
            'Libraries/Accounting/TaxReport.php',
        ];
        $found = [];
        $root  = realpath(__DIR__ . '/../../../app');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);

            if ($file->getExtension() !== 'php' || str_starts_with($relative, 'Database/') || str_starts_with($relative, 'Language/') || str_starts_with($relative, 'Views/')) {
                continue;
            }

            if (preg_match("/(?:table|prefixTable)\\(\\s*'accounting_routing(?: [a-z]+)?'/", (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = str_replace('\\', '/', $relative);
            }
        }

        sort($found);

        $this->assertSame($allowed, $found, 'A file reads the routing table directly: posting code must call RoutingResolver instead.');
    }

    public function testSystemAccount_ReturnsTheAccountTheChartGaveTheKey(): void
    {
        $this->assertSame($this->accountId('other_payables'), $this->resolver->systemAccount('other_payables'));
    }

    public function testSystemAccount_RefusesAKeyNoAccountHas(): void
    {
        $this->assertSystemAccountUnusable('no_such_system_key');
    }

    public function testSystemAccount_RefusesAnAccountThatIsAGroup(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'other_payables')->update(['is_group' => 1]);

        $this->assertSystemAccountUnusable('other_payables');
    }

    public function testSystemAccount_RefusesAnAccountThatIsInactive(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'other_payables')->update(['active' => 0]);

        $this->assertSystemAccountUnusable('other_payables');
    }

    public function testSystemAccount_RefusesAnAccountOfAnotherCompany(): void
    {
        $db = $this->accountingDb();

        $db->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);

        try {
            $db->table('accounting_accounts')->where('system_key', 'other_payables')->update(['company_id' => 2]);

            $this->assertSystemAccountUnusable('other_payables');
        } finally {
            $db->table('accounting_accounts')->where('system_key', 'other_payables')->update(['company_id' => 1]);
            $db->table('companies')->where('company_id', 2)->delete();
        }
    }

    private function assertSystemAccountUnusable(string $systemKey): void
    {
        try {
            $this->resolver->systemAccount($systemKey);
            $this->fail("{$systemKey} should have been refused.");
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::RoutingAccountUnusable, $failure->error);
            $this->assertSame($systemKey, $failure->context['routing_key']);
        }
    }

    public function testAccountFor_RoutesTheStockPermitsToTheCounterAccountsOfTheReferenceSystem(): void
    {
        $case = $this->referenceCase('T-53', 'stock_permit_accounts', 'counter-accounts-of-the-stock-permits', 'exact');
        $code = fn (RoutingKey $key): string => (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->resolver->accountFor($key))->get()->getRow()->code;

        $this->assertSameAsFixture($case['expected'], ['accounts' => ['in_permit' => $code(RoutingKey::StockPermitAdd), 'out_permit' => $code(RoutingKey::StockPermitIssue)]], 'counter accounts of the permits');
    }

    private function nameItemAccount(int $itemId, RoutingKey $key, int $accountId): void
    {
        $this->accountingDb()->table('item_routing_accounts')->replace(['item_id' => $itemId, 'routing_key' => $key->value, 'account_id' => $accountId]);
    }

    private function assertItemAccountRefused(RoutingKey $key, int $itemId, string $problem): void
    {
        try {
            $this->resolver->accountFor($key, null, $itemId);
            $this->fail('An account that cannot take entries must stop the posting.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::ItemAccountUnusable, $failure->error);
            $this->assertSame($itemId, $failure->context['item_id']);
            $this->assertSame($key->value, $failure->context['routing_key']);
            $this->assertSame($problem, $failure->context['problem']);
        }
    }

    public function testTypeOf_ReturnsTheTypeTheRowIsSavedWithOrItsDefault(): void
    {
        $this->assertSame(RoutingType::Auto, $this->resolver->typeOf(RoutingKey::SalesRevenue));
        $this->assertSame(RoutingType::Cancelled, $this->resolver->typeOf(RoutingKey::SalesDiscount));

        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $this->assertSame(RoutingType::PerItem, $this->resolver->typeOf(RoutingKey::SalesRevenue));
    }

    public function testAccountFor_ReturnsTheAccountTheItemNamesForAPerItemRow(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $account);

        $this->assertSame($account, $this->resolver->accountFor(RoutingKey::SalesRevenue, null, $itemId));
    }

    public function testAccountFor_FallsBackToTheAccountOfTheRowForAnItemThatNamesNone(): void
    {
        $this->setRouting(RoutingKey::SalesReturns, RoutingType::PerItem);
        $itemId = $this->createTestItem();

        $this->assertSame($this->accountId('sales_returns'), $this->resolver->accountFor(RoutingKey::SalesReturns, null, $itemId));
    }

    public function testAccountFor_FallsBackToTheAccountOfTheRowWhenNoItemIsGiven(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $this->assertSame($this->accountId('sales_revenue'), $this->resolver->accountFor(RoutingKey::SalesRevenue));
    }

    public function testAccountFor_TakesTheAccountOfTheItemForTheRowItWasNamedForOnly(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $this->setRouting(RoutingKey::SalesReturns, RoutingType::PerItem);
        $itemId = $this->createTestItem();
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $this->customAccount('41', '4101', 'Item revenue'));

        $this->assertSame($this->accountId('sales_returns'), $this->resolver->accountFor(RoutingKey::SalesReturns, null, $itemId));
    }

    public function testAccountFor_IgnoresTheAccountsItemsNameWhileTheRowIsAutomatic(): void
    {
        $itemId = $this->createTestItem();
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $this->customAccount('41', '4101', 'Item revenue'));

        $this->assertSame($this->accountId('sales_revenue'), $this->resolver->accountFor(RoutingKey::SalesRevenue, null, $itemId));
    }

    public function testAccountFor_RejectsAnAccountAnItemNamesThatWasDeactivated(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $account);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);

        $this->assertItemAccountRefused(RoutingKey::SalesRevenue, $itemId, 'routing_account_inactive');
    }

    public function testAccountFor_NamesTheItemAndTheAccountInTheMessageOfAnAccountAnItemNamesThatCannotTakeEntries(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4105', 'Item revenue');
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['name' => 'Olive oil 5L']);
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $account);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);

        try {
            $this->resolver->accountFor(RoutingKey::SalesRevenue, null, $itemId);
            $this->fail('An account that cannot take entries must stop the posting.');
        } catch (PostingException $failure) {
            $this->assertSame('Olive oil 5L', $failure->context['name']);
            $this->assertSame('4105', $failure->context['code']);

            $message = AccountingMessage::of($failure);

            $this->assertStringContainsString('"Olive oil 5L"', $message);
            $this->assertStringContainsString('4105', $message);
        }
    }

    public function testItemAccountsFor_RejectsAnAccountNamedByADeletedItemBecauseTheDocumentsThatHoldItAreStillPosted(): void
    {
        $this->setRouting(RoutingKey::SalesReturns, RoutingType::PerItem);
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4105', 'Item returns');
        $this->nameItemAccount($itemId, RoutingKey::SalesReturns, $account);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['deleted' => 1]);

        try {
            $this->resolver->itemAccountsFor(RoutingKey::SalesReturns, [$itemId]);
            $this->fail('A deleted item is resolved as any other.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::ItemAccountUnusable, $failure->error);
            $this->assertSame($itemId, $failure->context['item_id']);
        }
    }

    public function testAccountFor_RejectsAnAccountAnItemNamesThatBecameAGroup(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $account);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['is_group' => 1]);

        $this->assertItemAccountRefused(RoutingKey::SalesRevenue, $itemId, 'routing_account_not_postable');
    }

    public function testAccountFor_RejectsAnAccountAnItemNamesThatIsOfAnotherType(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $itemId = $this->createTestItem();
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $this->accountId('cogs'));

        $this->assertItemAccountRefused(RoutingKey::SalesRevenue, $itemId, 'routing_account_type_invalid');
    }

    public function testAccountFor_RejectsAnAccountAnItemNamesThatTheChartReservesForAnotherRow(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $itemId = $this->createTestItem();
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $this->accountId('sales_returns'));

        $this->assertItemAccountRefused(RoutingKey::SalesRevenue, $itemId, 'routing_account_reserved');
    }

    public function testAccountFor_RejectsAnAccountAnItemNamesThatBelongsToAnEntity(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['entity_type' => 'customer', 'entity_id' => 999999]);
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $account);

        try {
            $this->assertItemAccountRefused(RoutingKey::SalesRevenue, $itemId, 'routing_account_reserved');
        } finally {
            $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['entity_type' => null, 'entity_id' => null]);
        }
    }

    public function testAccountFor_RejectsAnAccountAnItemNamesThatBelongsToAnotherCompany(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $db      = $this->accountingDb();
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $account);
        $db->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);

        try {
            $db->table('accounting_accounts')->where('account_id', $account)->update(['company_id' => 2]);

            $this->assertItemAccountRefused(RoutingKey::SalesRevenue, $itemId, 'account_not_found');
        } finally {
            $db->table('accounting_accounts')->where('account_id', $account)->update(['company_id' => 1]);
            $db->table('companies')->where('company_id', 2)->delete();
        }
    }

    public function testAccountFor_RejectsAPerItemRowWhoseAccountIsMissingForAnItemThatNamesNone(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['account_id' => null]);

        $this->assertUnusable(RoutingKey::SalesRevenue);
    }

    public function testItemAccountsFor_ReturnsAnAccountForEveryItemAndSaysWhoseItIs(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $named   = $this->createTestItem();
        $plain   = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->nameItemAccount($named, RoutingKey::SalesRevenue, $account);

        $accounts = $this->resolver->itemAccountsFor(RoutingKey::SalesRevenue, [$named, $plain, $named]);

        $this->assertEquals([$named => new ItemRoutedAccount($account, true), $plain => new ItemRoutedAccount($this->accountId('sales_revenue'), false)], $accounts);
    }

    public function testItemAccountsFor_NeedsTheAccountOfTheRowOnlyForTheItemsThatNameNone(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['account_id' => null]);
        $named   = $this->createTestItem();
        $plain   = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->nameItemAccount($named, RoutingKey::SalesRevenue, $account);

        $this->assertEquals([$named => new ItemRoutedAccount($account, true)], $this->resolver->itemAccountsFor(RoutingKey::SalesRevenue, [$named]));

        try {
            $this->resolver->itemAccountsFor(RoutingKey::SalesRevenue, [$named, $plain]);
            $this->fail('An item without an account of its own cannot fall back to an empty row.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::RoutingAccountUnusable, $failure->error);
        }
    }

    public function testItemAccountsFor_RejectsARowThatIsNotSetToPerItem(): void
    {
        $itemId = $this->createTestItem();

        try {
            $this->resolver->itemAccountsFor(RoutingKey::SalesRevenue, [$itemId]);
            $this->fail('The accounts of items are read only for a row set to per item.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
            $this->assertSame('auto', $failure->context['routing_type']);
        }
    }

    public function testAccountFor_ReturnsTheAccountOfTheEntityForAMainPerEntityRow(): void
    {
        $this->setRouting(RoutingKey::Customers, RoutingType::MainPerEntity);
        $customerId = $this->insertCustomer();
        $accountId  = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->assertSame($accountId, $this->resolver->accountFor(RoutingKey::Customers, $customerId));
    }

    public function testAccountFor_RejectsAMainPerEntityRowForAnEntityWithoutAnAccountEvenWhenTheRowHasAPostableAccount(): void
    {
        $this->setRouting(RoutingKey::Customers, RoutingType::MainPerEntity);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => $this->accountId('cash')]);
        $customerId = $this->insertCustomer();

        try {
            $this->resolver->accountFor(RoutingKey::Customers, $customerId);
            $this->fail('A document of a customer must never land on a shared account.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::EntityAccountMissing, $failure->error);
            $this->assertSame($customerId, $failure->context['entity_id']);
        }
    }

    public function testAccountFor_UsesTheAccountOfTheRowForAMainPerEntityRowWhenTheDocumentHasNoEntity(): void
    {
        $this->setRouting(RoutingKey::Customers, RoutingType::MainPerEntity);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => $this->accountId('counter_customers')]);

        $this->assertSame($this->accountId('counter_customers'), $this->resolver->accountFor(RoutingKey::Customers));
    }

    public function testAccountFor_RejectsAMainPerEntityRowWithoutAnEntityWhenTheRowHasOnlyAMainAccount(): void
    {
        $this->setRouting(RoutingKey::Customers, RoutingType::MainPerEntity);

        try {
            $this->resolver->accountFor(RoutingKey::Customers);
            $this->fail('A group cannot take the entries of a document.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::EntityAccountMissing, $failure->error);
        }
    }

    public function testAccountFor_RejectsMainPerEntityOnARowTheReferenceSystemDoesNotOfferItOn(): void
    {
        $this->setRouting(RoutingKey::Suppliers, RoutingType::MainPerEntity);
        $supplierId = $this->insertSupplier();
        (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $supplierId, 'Gulf Supplies');

        try {
            $this->resolver->accountFor(RoutingKey::Suppliers, $supplierId);
            $this->fail('The row does not offer the type.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
            $this->assertSame('main_per_entity', $failure->context['routing_type']);
        }
    }

    public function testAccountFor_RejectsPerItemOnTheRowsWhoseTiesAreKeptByLocationAndOnTheEntityRows(): void
    {
        foreach ([RoutingKey::StockLocations, RoutingKey::Customers, RoutingKey::Suppliers, RoutingKey::Treasuries, RoutingKey::ProductSales, RoutingKey::Purchases] as $key) {
            $this->setRouting($key, RoutingType::PerItem);

            try {
                $this->resolver->accountFor($key, $this->entityIdFor($key), $this->createTestItem());
                $this->fail("{$key->value} must not resolve as per item.");
            } catch (PostingException $failure) {
                $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error, $key->value);
            }
        }
    }
}
