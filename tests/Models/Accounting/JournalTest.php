<?php

namespace Tests\Models\Accounting;

use App\Libraries\Accounting\PostingService;
use App\Models\Accounting\Journal;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class JournalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();
        $this->setConfigValue('accounting_base_currency', 'SAR');
    }

    public function testFindBySource_ReturnsTheJournalOfThatSourceAndRevision(): void
    {
        $posted = (new PostingService())->post($this->balancedDraft(sourceId: 41, revision: 2));

        $journal = model(Journal::class)->findBySource('sale', 41, 2);

        $this->assertNotNull($journal);
        $this->assertSame($posted->journalId, (int) $journal->journal_id);
    }

    public function testFindBySource_ReturnsNullForADifferentRevision(): void
    {
        (new PostingService())->post($this->balancedDraft(sourceId: 42, revision: 1));

        $this->assertNull(model(Journal::class)->findBySource('sale', 42, 2));
    }
}
