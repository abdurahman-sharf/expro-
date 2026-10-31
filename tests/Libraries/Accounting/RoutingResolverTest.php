<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingFailureLog;
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
use Tests\Support\ParityCaseTrait;

class RoutingResolverTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
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

    public function testIsImplemented_NamesTheTypesPostingApplies(): void
    {
        $this->assertSame(
            ['auto' => true, 'per_item' => false, 'cancelled' => true, 'main_per_entity' => false],
            array_combine(
                array_map(static fn (RoutingType $type): string => $type->value, RoutingType::cases()),
                array_map(static fn (RoutingType $type): bool => $type->isImplemented(), RoutingType::cases()),
            ),
        );
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
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $journals = $this->countRows('accounting_journals');

        try {
            $this->resolver->accountFor(RoutingKey::SalesRevenue);
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
        $this->assertSame('sales.revenue', json_decode($row->context, true)['routing_key']);
    }

    /**
     * Posting code must not read the routing table itself, because it would take the account of a
     * row without looking at its type. The files below are the ones that read it today, each for a
     * reason that is not choosing an account for a journal line: seeding, the screen, the
     * readiness check, the entity main account, the opening balance exclusions, the chart and the
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
            'Libraries/Accounting/InventoryValuation.php',
            'Libraries/Accounting/OpeningBalanceService.php',
            'Libraries/Accounting/RoutingResolver.php',
            'Libraries/Accounting/RoutingService.php',
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
}
