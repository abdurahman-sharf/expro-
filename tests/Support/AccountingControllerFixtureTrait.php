<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;

trait AccountingControllerFixtureTrait
{
    use AccountingFixtureTrait;
    use FeatureTestTrait;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function prepareAccounting(bool $enabled = true): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_enabled', $enabled ? '1' : '0');
    }

    protected function actAs(int $personId): void
    {
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);
    }

    protected function actAsEveryPermission(): int
    {
        $personId = $this->employeeWithGrants($this->allAccountingPermissions());
        $this->actAs($personId);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonOf(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    /**
     * @return list<string>
     */
    protected function journalPermissions(string ...$extra): array
    {
        return ['accounting_journals', AccountingGuard::JOURNALS_DRAFT, ...$extra];
    }

    /**
     * Posts a balanced journal through the service and returns its id. Without an employee the
     * system posts it, so the call needs no permission.
     */
    protected function postedJournalBy(?int $employeeId = null, string $amount = '100.00'): int
    {
        return (new JournalService())->postNew($this->journalInput($amount), $employeeId);
    }
}
