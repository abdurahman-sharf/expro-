<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Tax\VatReturns;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class VatReturnsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('vat_returns')->truncate();
        $db->table('accounting_closed_periods')->where('note', 'vat return test')->delete();

        parent::tearDown();
    }

    private function refusal(callable $action): AccountingError
    {
        try {
            $action();
        } catch (AccountingException $refusal) {
            return $refusal->error;
        }

        $this->fail('The return was not refused.');
    }

    public function testSaveDraft_KeepsOneDraftForAPeriodAndWorksItOutAgainEachTime(): void
    {
        $returns = new VatReturns();
        $first   = $returns->saveDraft('2026-10-01', '2026-10-31', [], 1);
        $again   = $returns->saveDraft('2026-10-01', '2026-10-31', ['15' => ['vat' => '12.00']], 1);
        $boxes   = json_decode((string) $returns->find($again)->boxes, true);

        $this->assertSame($first, $again);
        $this->assertSame('12.00', $boxes['15']['vat']);
        $this->assertSame(VatReturns::DRAFT, $returns->find($first)->status);
    }

    public function testApprove_RequiresTheBooksClosedThroughThePeriodAndThenFreezesTheReturn(): void
    {
        $returns  = new VatReturns();
        $returnId = $returns->saveDraft('2026-10-01', '2026-10-31', [], 1);

        $this->assertSame(AccountingError::VatReturnPeriodOpen, $this->refusal(fn () => $returns->approve($returnId, 1)));

        Database::connect()->table('accounting_closed_periods')->insert(['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'note' => 'vat return test', 'company_id' => 1]);

        $returns->approve($returnId, 1);

        $this->assertSame(VatReturns::APPROVED, $returns->find($returnId)->status);
        $this->assertSame(AccountingError::VatReturnApproved, $this->refusal(fn () => $returns->saveDraft('2026-10-01', '2026-10-31', [], 1)));
        $this->assertSame(AccountingError::VatReturnApproved, $this->refusal(fn () => $returns->approve($returnId, 1)));
    }
}
