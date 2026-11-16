<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingActivation;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Setup\FirstRun;
use App\Libraries\Setup\InstallState;
use Config\OSPOS;

/**
 * Puts a test database in the state of an installation in its first setup and keeps everything the setup writes
 * inside one transaction that the end of the test rolls back, so the next test starts from the same database.
 * Needs the accounting fixtures and the install state fixtures next to it.
 */
trait FirstRunFixtureTrait
{
    protected int $manager;

    protected function prepareFirstRun(): void
    {
        $db = $this->accountingDb();
        $db->disableForeignKeyChecks();
        $db->table('accounting_fiscal_years')->truncate();
        $db->enableForeignKeyChecks();
        $db->table('accounting_accounts')->where('active', 0)->update(['active' => 1]);

        foreach (RoutingKey::cases() as $key) {
            $systemKey = $key->defaultSystemKey();

            $db->table('accounting_routing')
                ->where('routing_key', $key->value)
                ->update(['account_id' => $systemKey === null ? null : $this->accountId($systemKey), 'routing_type' => $key->defaultType()->value]);
        }

        (new EntityAccountService())->fill();

        foreach (PaymentType::cases() as $type) {
            if ($type->defaultSystemKey() !== null) {
                $db->table('accounting_routing')->where('routing_key', $type->routingKey())->update(['account_id' => $this->accountId($type->defaultSystemKey())]);
            }
        }

        foreach (['accounting_enabled' => '0', 'accounting_base_currency' => '', 'accounting_start_date' => '', 'accounting_activation' => '', FirstRun::PROGRESS_KEY => ''] as $key => $value) {
            $this->setConfigValue($key, $value);
        }

        $this->manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $db->transBegin();
        $this->setInstallState(InstallState::PENDING_SETUP);
    }

    protected function finishFirstRun(): void
    {
        $this->releaseOpenTransactions();
        config(OSPOS::class)->update_settings();
        $this->removeInsertedEntities();
    }

    protected function activateTheBooks(string $currency = 'EGP'): void
    {
        $activation = new AccountingActivation();
        $state      = $activation->begin($this->manager, $currency, '2026-11-01', [AccountingActivation::ACK_IMPACT]);

        for ($turn = 0; $turn < 50; $turn++) {
            if ($activation->step($state['run_id'], $this->manager)['done']) {
                return;
            }
        }

        $this->fail('The activation did not finish.');
    }
}
