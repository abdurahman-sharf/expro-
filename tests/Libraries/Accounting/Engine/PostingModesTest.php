<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\Engine\PostingGaps;
use App\Libraries\Accounting\Engine\PostingMode;
use App\Libraries\Accounting\Engine\PostingModes;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PostingModesTest extends CIUnitTestCase
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

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->table('accounting_posting_modes')->where('operation', 'probe')->delete();
        $db->table('accounting_posting_modes')->replace(['company_id' => 1, 'operation' => 'expense', 'mode' => 'shadow']);
        $db->table('accounting_audit_log')->where('entity_type', 'posting_mode')->delete();

        parent::tearDown();
    }

    public function testOf_PostsAnOperationWithoutAModeByItsOwnCodeAndTheExpenseInShadow(): void
    {
        $this->assertSame(PostingMode::Legacy, (new PostingModes())->of('probe'));
        $this->assertSame(PostingMode::Shadow, (new PostingModes())->of('expense'));
    }

    public function testSet_ChangesTheModeOfOneOperationAndWritesTheAuditLogWithTheEmployee(): void
    {
        (new PostingModes())->set('probe', PostingMode::Engine, 1);
        (new PostingModes())->set('probe', PostingMode::Engine, 1);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'posting_mode')->get()->getResult();

        $this->assertSame(PostingMode::Engine, (new PostingModes())->of('probe'));
        $this->assertSame(PostingMode::Shadow, (new PostingModes())->of('expense'));
        $this->assertCount(1, $audit, 'Setting the mode it already has writes nothing');
        $this->assertSame(['changed', '1'], [$audit[0]->action, (string) $audit[0]->employee_id]);
        $this->assertSame(['operation' => 'probe', 'before' => 'legacy', 'after' => 'engine'], json_decode((string) $audit[0]->details, true));
    }

    public function testSet_RejectsTheEngineForAnOperationWithAnOpenGapAndKeepsItsMode(): void
    {
        $this->accountingDb()->table('accounting_posting_modes')->replace(['company_id' => 1, 'operation' => 'supplier_advance', 'mode' => 'shadow']);

        try {
            (new PostingModes())->set('supplier_advance', PostingMode::Engine, 1);
            $this->fail('The engine was switched on.');
        } catch (PostingException $refused) {
            $this->assertSame(PostingError::EngineGapOpen, $refused->error);
            $this->assertSame('supplier_advance', $refused->context['code']);
            $this->assertStringContainsString('opening balance', AccountingMessage::of($refused));
        }

        (new PostingModes())->set('supplier_advance', PostingMode::Legacy, 1);

        $this->assertSame(PostingMode::Legacy, (new PostingModes())->of('supplier_advance'), 'An operation with a gap may still go back to its code');
        $this->assertSame(['supplier_advance', 'sale', 'sale_cost', 'purchase'], array_keys(PostingGaps::OPEN));
        $this->accountingDb()->table('accounting_posting_modes')->replace(['company_id' => 1, 'operation' => 'supplier_advance', 'mode' => 'shadow']);
    }
}
