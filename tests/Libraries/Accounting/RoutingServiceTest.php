<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReadinessCode;
use App\Libraries\Accounting\ReadinessIssue;
use App\Libraries\Accounting\RoutingException;
use App\Libraries\Accounting\RoutingItem;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingService;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleFixtureTrait;

class RoutingServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;
    use SaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private RoutingService $service;
    private int $manager;

    /**
     * @var list<int>
     */
    private array $createdSales = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->accountingDb()->table('sales_payments')->truncate();
        $this->accountingDb()->table('sales_items_taxes')->truncate();
        $this->service = new RoutingService();
        $this->manager = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        foreach ($this->createdSales as $saleId) {
            foreach (['sales_payments', 'sales_items_taxes', 'sales_items'] as $table) {
                $db->table($table)->where('sale_id', $saleId)->delete();
            }

            $db->table('sales')->where('sale_id', $saleId)->delete();
        }

        $this->createdSales = [];
        $this->reinstallChart();

        parent::tearDown();
    }

    /**
     * @return array<string, RoutingItem>
     */
    private function itemsByKey(): array
    {
        $byKey = [];

        foreach ($this->service->items() as $item) {
            $byKey[$item->key] = $item;
        }

        return $byKey;
    }

    private function newSale(?string $paymentName = null): int
    {
        $saleId               = $this->createSale($this->manager);
        $this->createdSales[] = $saleId;

        if ($paymentName !== null) {
            $this->accountingDb()->table('sales_payments')->insert(['sale_id' => $saleId, 'payment_type' => $paymentName, 'payment_amount' => '10.00']);
        }

        return $saleId;
    }

    /**
     * @param int|string|array<string, string>|null $value
     */
    private function assertRowIsRefused(AccountingError $expected, string $key, int|string|array|null $value): void
    {
        try {
            $this->service->save([$key => $value], $this->manager);
            $this->fail("The row {$key} should have been refused.");
        } catch (RoutingException $exception) {
            $this->assertSame([$key => $expected], $exception->errors);
        }
    }

    private function needsUpiToBeOptional(): void
    {
        if (in_array(PaymentType::Upi, $this->service->requiredPaymentTypes(), true)) {
            $this->markTestSkipped('This install offers UPI, so its row is required.');
        }
    }

    public function testItems_ListsTheSalesRowsThenOneRowForEachPaymentType(): void
    {
        $keys = array_map(static fn (RoutingItem $item): string => $item->key, $this->service->items());

        $this->assertSame([
            'sales.customers', 'sales.revenue', 'sales.returns', 'sales.discount', 'sales.product', 'sales.adjustment', 'sales.shipping', 'sales.cogs',
            'purchases.suppliers', 'purchases.purchases', 'purchases.returns', 'purchases.discount', 'purchases.adjustment',
            'inventory.locations', 'treasuries.accounts', 'treasuries.banks', 'branches.receivable', 'branches.payable',
            'cheques.receivable', 'cheques.collection', 'cheques.fees', 'cheques.payable',
            'stock_permits.add', 'stock_permits.issue', 'stock_permits.count_out', 'stock_permits.count_in',
            'fiscal.retained_earnings',
            'cash.difference', 'tax.output', 'giftcards.liability', 'sales.counter',
            'payment.cash', 'payment.debit', 'payment.credit', 'payment.check', 'payment.bank_transfer', 'payment.upi', 'payment.wallet', 'payment.due', 'payment.giftcard', 'payment.rewards',
        ], $keys);
    }

    public function testItems_StartsEveryRowOnItsDefaultAccountAndType(): void
    {
        foreach (RoutingKey::editable() as $key) {
            $item      = $this->itemsByKey()[$key->value];
            $systemKey = $key->defaultSystemKey();

            $this->assertSame($systemKey === null ? null : $this->accountId($systemKey), $item->accountId, $key->value);
            $this->assertSame($item->accountId, $item->defaultAccountId, $key->value);
            $this->assertSame($key->defaultType()->value, $item->type, $key->value);
            $this->assertSame($key->isEntity(), $item->isEntity, $key->value);
            $this->assertFalse($item->locked, $key->value);
            $this->assertSame($key->group(), $item->group, $key->value);
        }
    }

    public function testItems_OffersTheRoutingTypesOfEachRow(): void
    {
        $items = $this->itemsByKey();

        $this->assertSame(['auto', 'per_item', 'main_per_entity'], $items['sales.customers']->routingTypes);
        $this->assertSame(['cancelled', 'auto'], $items['sales.discount']->routingTypes);
        $this->assertSame(['auto'], $items['fiscal.retained_earnings']->routingTypes);
        $this->assertSame([], $items['payment.cash']->routingTypes);
    }

    public function testItems_MarksACancelledRowCompleteWithoutAnAccount(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.discount')->update(['account_id' => null]);

        $this->assertSame(RoutingItem::COMPLETE, $this->itemsByKey()['sales.discount']->status);
    }

    public function testItems_LetsProductSalesAndPurchaseReturnsStayWithoutAnAccount(): void
    {
        $items = $this->itemsByKey();

        $this->assertSame(RoutingItem::COMPLETE, $items['sales.product']->status);
        $this->assertSame(RoutingItem::OPTIONAL, $items['purchases.returns']->status);
    }

    public function testItems_MarksAPostableAccountOnAnEntityRowInvalid(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => $this->accountId('cash')]);

        $this->assertSame(RoutingItem::INVALID, $this->itemsByKey()['sales.customers']->status);
    }

    public function testItems_ShowsCreditSalesAndGiftCardsAgainstTheirControlAccounts(): void
    {
        $items = $this->itemsByKey();

        $this->assertTrue($items['payment.due']->locked);
        $this->assertSame($this->accountId('receivables'), $items['payment.due']->accountId);
        $this->assertSame('sales.customers', $items['payment.due']->lockedTo);
        $this->assertSame(RoutingItem::COMPLETE, $items['payment.due']->status);
        $this->assertTrue($items['payment.giftcard']->locked);
        $this->assertSame($this->accountId('giftcard_liability'), $items['payment.giftcard']->accountId);
    }

    public function testItems_MarksARowSavedWithATypeNothingAppliesAsNotAppliedYet(): void
    {
        $this->service->save(['sales.customers' => ['type' => 'per_item'], 'purchases.suppliers' => ['type' => 'per_item'], 'inventory.locations' => ['type' => 'per_item']], $this->manager);

        $items = $this->itemsByKey();

        $this->assertSame(RoutingItem::UNSUPPORTED, $items['sales.customers']->status);
        $this->assertSame(RoutingItem::UNSUPPORTED, $items['purchases.suppliers']->status);
        $this->assertSame(RoutingItem::UNSUPPORTED, $items['inventory.locations']->status);
        $this->assertSame(RoutingItem::COMPLETE, $items['sales.returns']->status);
    }

    public function testItems_MarksTheTypesThatPostingAppliesAsAppliedAndCountsTheItemsThatNameAnAccount(): void
    {
        $itemId = $this->createTestItem();
        $this->service->save(['sales.revenue' => ['type' => 'per_item'], 'sales.returns' => ['type' => 'per_item'], 'sales.customers' => ['type' => 'main_per_entity']], $this->manager);
        $this->accountingDb()->table('item_routing_accounts')->insert(['item_id' => $itemId, 'routing_key' => 'sales.revenue', 'account_id' => $this->accountId('sales_revenue')]);

        $items = $this->itemsByKey();

        $this->assertSame(RoutingItem::COMPLETE, $items['sales.revenue']->status);
        $this->assertSame(RoutingItem::COMPLETE, $items['sales.returns']->status);
        $this->assertSame(RoutingItem::COMPLETE, $items['sales.customers']->status);
        $this->assertSame(1, $items['sales.revenue']->itemAccounts);
        $this->assertSame(0, $items['sales.returns']->itemAccounts);
        $this->assertNull($items['sales.customers']->itemAccounts);
        $this->assertNull($items['sales.cogs']->itemAccounts);
    }

    public function testItems_MarksAPerItemRowWithoutAnAccountToFallBackToMissing(): void
    {
        $this->service->save(['sales.revenue' => ['type' => 'per_item']], $this->manager);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['account_id' => null]);

        $this->assertSame(RoutingItem::MISSING, $this->itemsByKey()['sales.revenue']->status);
    }

    public function testItems_MarksAnUnmappedSalesRowMissing(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => null]);

        $item = $this->itemsByKey()['sales.cogs'];

        $this->assertSame(RoutingItem::MISSING, $item->status);
        $this->assertNull($item->accountId);
        $this->assertNotNull($item->defaultAccountId);
    }

    public function testItems_MarksAnAccountThatBecameInactiveInvalid(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'sales_revenue')->update(['active' => 0]);

        $this->assertSame(RoutingItem::INVALID, $this->itemsByKey()['sales.revenue']->status);
    }

    public function testItems_MarksAnUnusedPaymentRowOptionalWhenItHasNoAccount(): void
    {
        $this->needsUpiToBeOptional();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.upi')->update(['account_id' => null]);

        $item = $this->itemsByKey()['payment.upi'];

        $this->assertSame(RoutingItem::OPTIONAL, $item->status);
        $this->assertFalse($item->required);
    }

    public function testSave_ChangesARowAndAuditsTheChange(): void
    {
        $other = $this->customAccount('41', '4110', 'Other sales');

        $this->service->save(['sales.revenue' => $other], $this->manager);

        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->get()->getRow();
        $this->assertSame($other, (int) $row->account_id);

        $entry   = $this->accountingDb()->table('accounting_audit_log')->where('action', 'routing_changed')->get()->getRow();
        $details = json_decode($entry->details, true);
        $this->assertSame('routing', $entry->entity_type);
        $this->assertSame($this->manager, (int) $entry->employee_id);
        $this->assertSame('sales.revenue', $details['key']);
        $this->assertSame('411', $details['before']['code']);
        $this->assertSame('4110', $details['after']['code']);
    }

    public function testSave_ChangesTheTypeOfARowAndAuditsIt(): void
    {
        $this->service->save(['sales.revenue' => ['type' => 'per_item', 'account' => (string) $this->accountId('sales_revenue')]], $this->manager);

        $this->assertSame('per_item', $this->itemsByKey()['sales.revenue']->type);

        $details = json_decode($this->accountingDb()->table('accounting_audit_log')->where('action', 'routing_changed')->get()->getRow()->details, true);
        $this->assertSame('auto', $details['type_before']);
        $this->assertSame('per_item', $details['type_after']);
    }

    public function testSave_KeepsTheCurrentTypeWhenOnlyTheAccountIsSent(): void
    {
        $other = $this->customAccount('41', '4110', 'Other sales');
        $this->service->save(['sales.revenue' => ['type' => 'cancelled']], $this->manager);

        $this->service->save(['sales.revenue' => ['account' => (string) $other]], $this->manager);

        $this->assertSame('cancelled', $this->itemsByKey()['sales.revenue']->type);
    }

    public function testSave_KeepsTheCurrentAccountWhenOnlyTheTypeIsSent(): void
    {
        $this->service->save(['sales.revenue' => ['type' => 'per_item']], $this->manager);

        $item = $this->itemsByKey()['sales.revenue'];

        $this->assertSame('per_item', $item->type);
        $this->assertSame($this->accountId('sales_revenue'), $item->accountId);
    }

    public function testSave_KeepsTheAccountOfARowThatIsCancelled(): void
    {
        $this->service->save(['sales.returns' => ['type' => 'cancelled', 'account' => '']], $this->manager);

        $item = $this->itemsByKey()['sales.returns'];

        $this->assertSame('cancelled', $item->type);
        $this->assertSame($this->accountId('sales_returns'), $item->accountId);
    }

    public function testSave_RejectsATypeTheRowDoesNotOffer(): void
    {
        foreach ([['sales.customers', 'cancelled'], ['sales.revenue', 'main_per_entity'], ['sales.discount', 'per_item'], ['fiscal.retained_earnings', 'cancelled'], ['sales.revenue', 'bogus']] as [$key, $type]) {
            try {
                $this->service->save([$key => ['type' => $type]], $this->manager);
                $this->fail("{$key} should refuse {$type}.");
            } catch (RoutingException $exception) {
                $this->assertSame([$key => AccountingError::RoutingTypeInvalid], $exception->errors);
            }
        }
    }

    public function testSave_RejectsATypeOnAPaymentRow(): void
    {
        $this->assertRowIsRefused(AccountingError::RoutingKeyUnknown, 'payment.cash', ['type' => 'auto', 'account' => (string) $this->accountId('cash')]);
    }

    public function testSave_AcceptsAGroupAccountForAnEntityRow(): void
    {
        $this->service->save(['sales.customers' => ['account' => (string) $this->accountIdByCode('1205')]], $this->manager);

        $this->assertSame($this->accountIdByCode('1205'), $this->itemsByKey()['sales.customers']->accountId);
    }

    public function testSave_RejectsAPostableAccountForAnEntityRow(): void
    {
        $this->assertRowIsRefused(AccountingError::RoutingAccountNotGroup, 'sales.customers', ['account' => (string) $this->accountId('cash')]);
    }

    public function testSave_LetsProductSalesAndPurchaseReturnsBeEmpty(): void
    {
        $this->service->save(['sales.product' => ['type' => 'per_item', 'account' => ''], 'purchases.returns' => ['account' => '']], $this->manager);

        $this->assertNull($this->itemsByKey()['purchases.returns']->accountId);
        $this->assertSame('per_item', $this->itemsByKey()['sales.product']->type);
    }

    public function testSave_LeavesTheRowsItIsNotGivenAsTheyWere(): void
    {
        $before = $this->accountingDb()->table('accounting_routing')->orderBy('routing_key')->get()->getResultArray();
        $other  = $this->customAccount('41', '4110', 'Other sales');

        $this->service->save(['sales.revenue' => $other], $this->manager);

        $changed = [];

        foreach ($this->accountingDb()->table('accounting_routing')->orderBy('routing_key')->get()->getResultArray() as $index => $row) {
            if ($row['account_id'] !== $before[$index]['account_id']) {
                $changed[] = $row['routing_key'];
            }
        }

        $this->assertSame(['sales.revenue'], $changed);
    }

    public function testSave_WritesNothingWhenNothingChanges(): void
    {
        $this->service->save(['sales.revenue' => $this->accountId('sales_revenue')], $this->manager);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'routing_changed')->countAllResults());
    }

    public function testSave_RequiresTheRoutingPermission(): void
    {
        $viewer = $this->employeeWithGrants(['accounting_routing']);

        try {
            $this->service->save(['sales.revenue' => $this->accountId('sales_revenue')], $viewer);
            $this->fail('An employee without the permission should be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }
    }

    public function testSave_RequiresEveryBranch(): void
    {
        $limited = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE], [1]);

        $this->expectException(BranchException::class);

        $this->service->save(['sales.revenue' => $this->accountId('sales_revenue')], $limited);
    }

    public function testSave_AllowsTheSystemToChangeTheRouting(): void
    {
        $other = $this->customAccount('41', '4110', 'Other sales');

        $this->service->save(['sales.revenue' => $other]);

        $this->assertSame($other, $this->itemsByKey()['sales.revenue']->accountId);
    }

    public function testSave_RejectsAGroupAccount(): void
    {
        $this->assertRowIsRefused(AccountingError::RoutingAccountNotPostable, 'sales.revenue', $this->accountIdByCode('41'));
    }

    public function testSave_RejectsAnInactiveAccount(): void
    {
        $other = $this->customAccount('41', '4110', 'Other sales');
        (new AccountService())->deactivate($other);

        $this->assertRowIsRefused(AccountingError::RoutingAccountInactive, 'sales.revenue', $other);
    }

    public function testSave_RejectsAnAccountOfAnotherType(): void
    {
        $this->assertRowIsRefused(AccountingError::RoutingAccountTypeInvalid, 'sales.revenue', $this->customAccount('11', '1110', 'Extra asset'));
    }

    public function testSave_RejectsAnAccountReservedForAnotherPurpose(): void
    {
        $this->assertRowIsRefused(AccountingError::RoutingAccountReserved, 'sales.revenue', $this->accountId('sales_returns'));
    }

    public function testSave_RejectsAnAccountThatDoesNotExist(): void
    {
        $this->assertRowIsRefused(AccountingError::AccountNotFound, 'sales.revenue', 999999);
    }

    public function testSave_RejectsAnEmptySalesRow(): void
    {
        $this->assertRowIsRefused(AccountingError::RoutingAccountRequired, 'sales.revenue', '');
    }

    public function testSave_RejectsAnEmptyRequiredPaymentRow(): void
    {
        $this->assertRowIsRefused(AccountingError::RoutingAccountRequired, 'payment.cash', '');
    }

    public function testSave_RejectsTheLockedRowsAndKeysItDoesNotKnow(): void
    {
        $this->assertRowIsRefused(AccountingError::RoutingKeyUnknown, 'payment.due', $this->accountId('cash'));
        $this->assertRowIsRefused(AccountingError::RoutingKeyUnknown, 'payment.giftcard', $this->accountId('cash'));
        $this->assertRowIsRefused(AccountingError::RoutingKeyUnknown, 'cash.default', $this->accountId('cash'));
        $this->assertRowIsRefused(AccountingError::RoutingKeyUnknown, 'bogus', $this->accountId('cash'));
    }

    public function testSave_ReportsEveryProblemTogetherAndWritesNothing(): void
    {
        $other = $this->customAccount('41', '4110', 'Other sales');

        try {
            $this->service->save([
                'sales.revenue' => $other,
                'sales.cogs'    => $this->accountIdByCode('5'),
                'sales.returns' => '',
            ], $this->manager);
            $this->fail('The save should have been refused.');
        } catch (RoutingException $exception) {
            $this->assertSame([
                'sales.cogs'    => AccountingError::RoutingAccountNotPostable,
                'sales.returns' => AccountingError::RoutingAccountRequired,
            ], $exception->errors);
            $this->assertSame(AccountingError::RoutingInvalid, $exception->error);
        }

        $this->assertSame($this->accountId('sales_revenue'), $this->itemsByKey()['sales.revenue']->accountId);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'routing_changed')->countAllResults());
    }

    public function testSave_ClearsAnUnusedPaymentRow(): void
    {
        $this->needsUpiToBeOptional();

        $this->service->save(['payment.upi' => ''], $this->manager);

        $this->assertNull($this->itemsByKey()['payment.upi']->accountId);
    }

    public function testSave_AcceptsALiabilityAccountForAWalletRow(): void
    {
        $accrued = $this->customAccount('21', '2150', 'Accrued');

        $this->service->save(['payment.wallet' => $accrued], $this->manager);

        $this->assertSame($accrued, $this->itemsByKey()['payment.wallet']->accountId);
    }

    public function testSave_RefusesAnAccountThatSuitsAnotherRow(): void
    {
        $this->assertRowIsRefused(AccountingError::RoutingAccountTypeInvalid, 'payment.rewards', $this->accountId('purchases'));
        $this->assertRowIsRefused(AccountingError::RoutingAccountReserved, 'payment.cash', $this->accountId('other_receivables'));
    }

    public function testCandidates_ListsPostableActiveAccountsOfTheGivenTypesByCode(): void
    {
        $inactive = $this->customAccount('41', '4110', 'Retired sales');
        (new AccountService())->deactivate($inactive);

        $codes = array_map(static fn (object $account): string => $account->code, $this->service->candidates(['revenue']));

        $this->assertContains('411', $codes);
        $this->assertNotContains('41', $codes);
        $this->assertNotContains('4110', $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));
        $this->assertSame([], $this->service->candidates([]));
    }

    public function testCandidates_ListsGroupAccountsForTheRowsOfAnEntityKind(): void
    {
        $codes = array_map(static fn (object $account): string => $account->code, $this->service->candidates(['asset'], true));

        $this->assertContains('12041', $codes);
        $this->assertContains('1203', $codes);
        $this->assertNotContains('120101', $codes);
    }

    public function testRequiredPaymentTypes_AlwaysIncludeGiftCardsAndRewardPoints(): void
    {
        $required = $this->service->requiredPaymentTypes();

        $this->assertContains(PaymentType::Giftcard, $required);
        $this->assertContains(PaymentType::Rewards, $required);
        $this->assertContains(PaymentType::Cash, $required);
    }

    public function testRequiredPaymentTypes_IncludeATypeALastYearSaleUsed(): void
    {
        $this->needsUpiToBeOptional();
        $this->newSale('UPI');

        $this->assertContains(PaymentType::Upi, $this->service->requiredPaymentTypes());
    }

    public function testRequiredPaymentTypes_IgnoreASaleOlderThanAYear(): void
    {
        $this->needsUpiToBeOptional();
        $saleId = $this->newSale('UPI');
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => date('Y-m-d H:i:s', strtotime('-2 years'))]);

        $this->assertNotContains(PaymentType::Upi, $this->service->requiredPaymentTypes());
    }

    public function testBlockingIssues_AreNoneOnAFreshChart(): void
    {
        $this->assertSame([], $this->service->blockingIssues());
    }

    public function testBlockingIssues_ReportARequiredPaymentTypeWithoutAnAccount(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.wallet')->update(['account_id' => null]);

        $issues = $this->service->blockingIssues();

        $this->assertSame([ReadinessCode::PaymentTypeUnrouted], array_column($issues, 'code'));
        $this->assertSame('wallet', $issues[0]->subject);
    }

    public function testBlockingIssues_ReportAPaymentRowWhoseAccountIsInactive(): void
    {
        $other = $this->customAccount('11', '1110', 'Petty cash');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.check')->update(['account_id' => $other]);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $other)->update(['active' => 0]);

        $issues = $this->service->blockingIssues();

        $this->assertSame([ReadinessCode::RoutingAccountInvalid], array_column($issues, 'code'));
        $this->assertSame('payment.check', $issues[0]->subject);
    }

    public function testBlockingIssues_ReportAnAccountAnItemNamesThatCannotTakeEntriesWhenTheRowIsPerItem(): void
    {
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->accountingDb()->table('item_routing_accounts')->insert(['item_id' => $itemId, 'routing_key' => 'sales.revenue', 'account_id' => $account]);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);
        $this->service->save(['sales.revenue' => ['type' => 'per_item']], $this->manager);

        $issues = $this->service->blockingIssues();

        $this->assertSame([ReadinessCode::ItemAccountInvalid], array_column($issues, 'code'));
        $this->assertSame('1', $issues[0]->subject);
    }

    public function testBlockingIssues_IgnoreAnAccountAnItemNamesWhenTheRowIsNotPerItem(): void
    {
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->accountingDb()->table('item_routing_accounts')->insert(['item_id' => $itemId, 'routing_key' => 'sales.revenue', 'account_id' => $account]);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);

        $this->assertSame([], $this->service->blockingIssues());
    }

    public function testBlockingIssues_IgnoreAnAccountWhoseItemWasDeleted(): void
    {
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->accountingDb()->table('item_routing_accounts')->insert(['item_id' => $itemId, 'routing_key' => 'sales.revenue', 'account_id' => $account]);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['deleted' => 1]);
        $this->service->save(['sales.revenue' => ['type' => 'per_item']], $this->manager);

        $this->assertSame([], $this->service->blockingIssues());
    }

    public function testBlockingIssues_ReportAStockLocationSoldFromThatBelongsToNoActiveBranch(): void
    {
        $inactive = $this->createBranch('OLD', 'Closed branch', false);
        $this->accountingDb()->table('stock_locations')->where('location_id', 1)->update(['branch_id' => $inactive]);
        $this->newSale();
        $name = $this->accountingDb()->table('stock_locations')->where('location_id', 1)->get()->getRow()->location_name;

        $issues = $this->service->blockingIssues();

        $this->assertSame([ReadinessCode::StockLocationWithoutBranch], array_column($issues, 'code'));
        $this->assertSame($name, $issues[0]->subject);
    }

    public function testBlockingIssues_IgnoreAStockLocationNothingWasSoldFrom(): void
    {
        $inactive = $this->createBranch('OLD', 'Closed branch', false);
        $this->accountingDb()->table('stock_locations')->insert(['location_name' => 'Idle store', 'deleted' => 0, 'branch_id' => $inactive]);
        $idle = (int) $this->accountingDb()->insertID();

        $subjects = array_map(static fn (ReadinessIssue $issue): ?string => $issue->subject, $this->service->blockingIssues());
        $this->accountingDb()->table('stock_locations')->where('location_id', $idle)->delete();

        $this->assertNotContains('Idle store', $subjects);
    }

    public function testNotices_ReportAPaymentNameNoTypeHas(): void
    {
        $this->newSale('Barter');

        $notices = $this->service->notices(null);

        $this->assertSame([ReadinessCode::PaymentTypeUnknown], array_column($notices, 'code'));
        $this->assertSame('Barter', $notices[0]->subject);
    }

    public function testNotices_ReportSalesBeforeTheStartDate(): void
    {
        $this->newSale();

        $notices = $this->service->notices(date('Y-m-d', strtotime('+1 day')));

        $this->assertContains(ReadinessCode::SalesBeforeStartDate, array_column($notices, 'code'));
        $this->assertNotContains(ReadinessCode::SalesBeforeStartDate, array_column($this->service->notices(null), 'code'));
    }

    public function testNotices_ReportSeveralOutputTaxesPostedToOneAccount(): void
    {
        $saleId = $this->newSale();
        $itemId = (int) $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->get()->getRow()->item_id;

        foreach ([['VAT', '15.0000'], ['Service', '5.0000']] as [$name, $percent]) {
            $this->accountingDb()->table('sales_items_taxes')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'name' => $name, 'percent' => $percent]);
        }

        $notices = $this->service->notices(null);

        $this->assertSame([ReadinessCode::TaxOutputSharedAccount], array_column($notices, 'code'));
        $this->assertSame('2', $notices[0]->subject);
    }

    public function testNotices_LeaveOutTheTaxesThatHaveAnAccountOfTheirOwn(): void
    {
        $saleId  = $this->newSale();
        $itemId  = (int) $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->get()->getRow()->item_id;
        $account = $this->customAccount('215', '215003', 'Tax on services');

        $this->accountingDb()->table('sales_items_taxes')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'name' => 'VAT', 'percent' => '15.0000']);
        $this->accountingDb()->table('sales_items_taxes')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'name' => 'Service', 'percent' => '5.0000', 'liability_account_id' => $account]);

        $this->assertSame([], array_column($this->service->notices(null), 'code'), 'one tax is left for the routed account, which is not several');

        $this->accountingDb()->table('sales_items_taxes')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'name' => 'Levy', 'percent' => '2.0000']);

        $notices = $this->service->notices(null);

        $this->assertSame([ReadinessCode::TaxOutputSharedAccount], array_column($notices, 'code'));
        $this->assertSame('2', $notices[0]->subject, 'the two taxes that go to the routed account');
    }

    public function testNotices_AreNoneWhenNothingWorthMentioningHappened(): void
    {
        $this->assertSame([], array_map(static fn (ReadinessIssue $issue): string => $issue->code->value, $this->service->notices(null)));
    }
}
