<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AccountPicker;
use App\Libraries\Accounting\OpeningBalanceService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;

/**
 * The search behind the pickers: how many entries make a search box, what it offers (at most twenty, by code
 * or by name, in the branches of the employee), and what a screen gets to render below and above the threshold.
 *
 * @internal
 */
final class AccountPickerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private AccountPicker $picker;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_picker_threshold', '200');

        $this->picker = new AccountPicker();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('app_config')->where('key', 'accounting_picker_threshold')->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @return list<int>
     */
    private function manyAccounts(int $count, string $name = 'Bulk account'): array
    {
        $ids = [];

        for ($index = 1; $index <= $count; $index++) {
            $ids[] = $this->customAccount('11', sprintf('119%03d', $index), $name . ' ' . $index);
        }

        return $ids;
    }

    private function postable(): int
    {
        return $this->picker->count(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, null);
    }

    public function testThreshold_IsTwoHundredUntilTheSettingSaysOtherwise(): void
    {
        $this->accountingDb()->table('app_config')->where('key', 'accounting_picker_threshold')->delete();

        $this->assertSame(200, $this->picker->threshold());

        $this->setConfigValue('accounting_picker_threshold', '35');

        $this->assertSame(35, $this->picker->threshold());
    }

    public function testSearching_IsFalseWhileTheEntriesDoNotExceedTheThresholdAndTrueAboveIt(): void
    {
        $count = $this->postable();

        $this->setConfigValue('accounting_picker_threshold', (string) $count);
        $this->assertFalse($this->picker->searching(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, null), 'As many entries as the threshold is not above it');

        $this->setConfigValue('accounting_picker_threshold', (string) ($count - 1));
        $this->assertTrue($this->picker->searching(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, null));
    }

    public function testSearch_ReturnsAtMostTwentyEntriesAndSaysThereAreMore(): void
    {
        $this->manyAccounts(25);

        $found = $this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, 'Bulk account', null);

        $this->assertCount(20, $found['results']);
        $this->assertTrue($found['more']);
        $this->assertSame('119001', $found['results'][0]['code']);
        $this->assertSame('119001 - Bulk account 1', $found['results'][0]['label']);
    }

    public function testSearch_DoesNotSayThereAreMoreWhenExactlyTwentyMatch(): void
    {
        $this->manyAccounts(20, 'Exact account');

        $found = $this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, 'Exact account', null);

        $this->assertCount(20, $found['results']);
        $this->assertFalse($found['more']);
    }

    public function testSearch_MatchesTheStartOfACodeAndAnyPartOfAName(): void
    {
        $id = $this->customAccount('11', '119777', 'Zanzibar petty cash');

        $byCode = $this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, '1197', null);
        $byName = $this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, 'petty', null);
        $middle = $this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, '9777', null);

        $this->assertSame([$id], array_column($byCode['results'], 'id'));
        $this->assertSame([$id], array_column($byName['results'], 'id'));
        $this->assertSame([], $middle['results'], 'A code is matched from its start, not from the middle');
    }

    public function testSearch_TakesAWildcardCharacterLiterally(): void
    {
        $this->customAccount('11', '119778', 'Plain account');

        $this->assertSame([], $this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, '%', null)['results']);
        $this->assertSame([], $this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, 'Plain_account', null)['results']);
    }

    public function testSearch_OffersOnlyAccountsAnEntryCanBePostedTo(): void
    {
        $group    = $this->customAccount('11', '119800', 'Hidden group', true);
        $disabled = $this->customAccount('11', '119801', 'Hidden disabled');
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $disabled)->update(['active' => 0]);

        $postable = array_column($this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, 'Hidden', null)['results'], 'id');
        $every    = array_column($this->picker->search(AccountPicker::ACCOUNT, AccountPicker::EVERY, 'Hidden', null)['results'], 'id');

        $this->assertSame([], $postable);
        $this->assertEqualsCanonicalizing([$group, $disabled], $every);
    }

    public function testSearch_OffersNothingToAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->customAccount('11', '119802', 'Some account');

        $this->assertSame(['results' => [], 'more' => false], $this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, 'Some', []));
        $this->assertSame(0, $this->picker->count(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, []));
        $this->assertNull($this->picker->find(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, $this->accountId('cash'), []));
    }

    public function testSearch_LeavesOutTheStockAccountOfALocationInABranchTheEmployeeDoesNotWorkIn(): void
    {
        $second   = $this->createBranch('PK2', 'Picker branch');
        $own      = $this->anotherLocation('Picker own depot');
        $foreign  = $this->locationOfBranch($second, 'Picker foreign depot');
        $ownId    = $this->accountingDb()->table('accounting_accounts')->where(['entity_type' => 'stock_location', 'entity_id' => $own])->get()->getRow()->account_id;
        $otherId  = $this->accountingDb()->table('accounting_accounts')->where(['entity_type' => 'stock_location', 'entity_id' => $foreign])->get()->getRow()->account_id;
        $limited  = array_column($this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, 'Picker', [1])['results'], 'id');
        $other    = array_column($this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, 'Picker', [$second])['results'], 'id');
        $everyone = array_column($this->picker->search(AccountPicker::ACCOUNT, AccountPicker::POSTABLE, 'Picker', null)['results'], 'id');

        $this->assertSame([(int) $ownId], $limited);
        $this->assertSame([(int) $otherId], $other);
        $this->assertEqualsCanonicalizing([(int) $ownId, (int) $otherId], $everyone);
    }

    public function testSearch_OffersTheAccountsTheOpeningScreenListsAndNoOthers(): void
    {
        $allowed = array_map(static fn (object $account): int => (int) $account->account_id, (new OpeningBalanceService())->allowedAccounts());
        $offered = [];

        $count = $this->picker->count(AccountPicker::ACCOUNT, AccountPicker::OPENING, null);

        $this->assertSame(count($allowed), $count);

        foreach ($this->picker->search(AccountPicker::ACCOUNT, AccountPicker::OPENING, '', null)['results'] as $entry) {
            $offered[] = $entry['id'];
        }

        $this->assertSame(array_slice($allowed, 0, AccountPicker::LIMIT), $offered);
        $this->assertNotSame($count, $this->postable(), 'The opening screen lists fewer accounts than a journal does');
    }

    public function testSearch_FindsACustomerByNameOrCompanyAndASupplierByCompany(): void
    {
        $customer = $this->insertCustomer('Picker', 'Rahim', 'Rahim Traders');
        $supplier = $this->insertSupplier('Picker Wholesale', 'Nora', 'Hamad');
        $deleted  = $this->insertCustomer('Picker', 'Gone');
        $this->accountingDb()->table('customers')->where('person_id', $deleted)->update(['deleted' => 1]);

        $byName    = $this->picker->search(AccountPicker::CUSTOMER, AccountPicker::EVERY, 'Rahim', null)['results'];
        $byCompany = $this->picker->search(AccountPicker::CUSTOMER, AccountPicker::EVERY, 'Traders', null)['results'];
        $suppliers = $this->picker->search(AccountPicker::SUPPLIER, AccountPicker::EVERY, 'Wholesale', null)['results'];

        $this->assertSame([$customer], array_column($byName, 'id'));
        $this->assertSame('Rahim Traders', $byName[0]['label']);
        $this->assertSame([$customer], array_column($byCompany, 'id'));
        $this->assertSame([$supplier], array_column($suppliers, 'id'));
        $this->assertSame([], $this->picker->search(AccountPicker::CUSTOMER, AccountPicker::EVERY, 'Gone', null)['results'], 'A deleted customer is not offered');
        $this->assertSame([], $this->picker->search(AccountPicker::SUPPLIER, AccountPicker::EVERY, 'Rahim', null)['results'], 'A customer is not a supplier');
    }

    public function testFind_ReturnsTheEntryOfAnIdAndNothingForAnIdOfAnotherKind(): void
    {
        $customer = $this->insertCustomer('Picker', 'Finder');

        $this->assertSame(['id' => $customer, 'code' => '', 'label' => 'Picker Finder'], $this->picker->find(AccountPicker::CUSTOMER, AccountPicker::EVERY, $customer, null));
        $this->assertNull($this->picker->find(AccountPicker::SUPPLIER, AccountPicker::EVERY, $customer, null));
    }

    public function testScreen_ListsEveryAccountBelowTheThresholdAndOffersNoSearchBox(): void
    {
        $screen = $this->picker->screen(AccountPicker::POSTABLE, [], null, 'http://x/search', '.box', static fn (): array => [(object) ['account_id' => 1, 'code' => '1', 'name' => 'One']]);

        $this->assertNull($screen['picker']);
        $this->assertCount(1, $screen['accounts']);
    }

    public function testScreen_KeepsOnlyTheAccountsAlreadyOnTheScreenAboveTheThresholdAndSaysWhereToSearch(): void
    {
        $this->setConfigValue('accounting_picker_threshold', '1');
        $cash = $this->accountId('cash');

        $screen = $this->picker->screen(AccountPicker::POSTABLE, [$cash, $cash], null, 'http://x/search', '.box', static function (): array {
            self::fail('The whole list is not read above the threshold');
        });

        $this->assertCount(1, $screen['accounts']);
        $this->assertSame($cash, (int) $screen['accounts'][0]->account_id);
        $this->assertSame('http://x/search', $screen['picker']['url']);
        $this->assertSame('.box', $screen['picker']['selector']);
        $this->assertSame(['kind' => 'account', 'mode' => 'postable'], $screen['picker']['params']);
        $this->assertSame($cash, $screen['picker']['initial'][0]['id']);
        $this->assertSame(lang('Accounting_picker.none'), $screen['picker']['text']['none']);
        $this->assertSame(lang('Accounting_picker.more', [AccountPicker::LIMIT]), $screen['picker']['text']['more']);
    }

    public function testScreen_StillNamesAnAccountOnTheScreenThatIsOutsideTheBranchesOfTheEmployee(): void
    {
        $second  = $this->createBranch('PK3', 'Another picker branch');
        $foreign = $this->locationOfBranch($second, 'Picker named depot');
        $account = (int) $this->accountingDb()->table('accounting_accounts')->where(['entity_type' => 'stock_location', 'entity_id' => $foreign])->get()->getRow()->account_id;

        $this->setConfigValue('accounting_picker_threshold', '1');

        $screen = $this->picker->screen(AccountPicker::POSTABLE, [$account], [1], 'http://x/search', '.box', static fn (): array => []);

        $this->assertSame([$account], array_map(static fn (object $row): int => (int) $row->account_id, $screen['accounts']));
    }

    public function testThresholdSetting_IsReadByTheSettingsOfTheBooks(): void
    {
        $this->assertSame((new AccountingSettings())->pickerThreshold(), $this->picker->threshold());
    }
}
