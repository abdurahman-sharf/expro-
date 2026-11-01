<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\TaxDefinitions;
use App\Models\Tax_definition;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * What the tax list says about the taxes of an item, asked by name.
 *
 * @internal
 */
final class Tax_definitionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('tax_definitions')->truncate();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', TaxDefinitions::ENTITY)->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    public function testForNames_AnswersWhatTheListSaysAboutEachNameAndLeavesOutTheOthers(): void
    {
        $taxes   = new TaxDefinitions();
        $router  = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
        $account = $this->customAccount('215', '215003', 'Tax on services');

        $taxes->save(['tax_name' => 'VAT', 'tax_mode' => TaxDefinitions::INCLUDED], null, $router);
        $taxes->save(['tax_name' => 'Service', 'tax_mode' => TaxDefinitions::EXCLUDED, 'account_id' => $account], null, $router);
        $taxes->save(['tax_name' => 'Plain'], null, $router);

        $found = (new Tax_definition())->forNames(['vat', ' SERVICE ', 'Plain', 'Unknown', '']);

        $this->assertSame(
            [
                'vat'     => ['included' => true, 'account' => null],
                'service' => ['included' => false, 'account' => $account],
                'plain'   => ['included' => null, 'account' => null],
            ],
            $found,
        );
    }

    public function testForNames_AsksNothingOfTheDatabaseWhenThereAreNoNames(): void
    {
        $database  = Database::connect();
        $lastQuery = $database->getLastQuery();

        $this->assertSame([], (new Tax_definition())->forNames([]));
        $this->assertSame([], (new Tax_definition())->forNames(['', '  ']));
        $this->assertSame($lastQuery, $database->getLastQuery(), 'no query was sent');
    }

    public function testKey_IgnoresTheCaseAndTheSpacesAroundAName(): void
    {
        $this->assertSame(Tax_definition::key('VAT'), Tax_definition::key('  vat '));
        $this->assertNotSame(Tax_definition::key('VAT'), Tax_definition::key('VAT 2'));
    }
}
