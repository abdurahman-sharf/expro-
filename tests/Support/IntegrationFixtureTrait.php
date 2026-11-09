<?php

namespace Tests\Support;

use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationOptions;
use CodeIgniter\Test\TestLogger;
use Config\Database;
use Config\Logger as LoggerConfig;
use Config\Services;
use ReflectionProperty;

/**
 * What the tests of the integration settings start from: none of their tables holds a row, none of their
 * switches is set, and an employee can be made with exactly the permissions a case needs.
 */
trait IntegrationFixtureTrait
{
    use AccountingFixtureTrait;

    private const INTEGRATION_TABLES   = ['notification_reads', 'notifications', 'notification_rules', 'api_rate_windows', 'api_keys', 'entity_translations', 'payment_methods'];
    private const INTEGRATION_ENTITIES = ['integration', 'payment_methods', 'entity_translation', 'api_key', 'notification_rule', 'employee_permission'];

    protected function wipeIntegration(): void
    {
        $db = Database::connect();
        $this->releaseOpenTransactions();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::INTEGRATION_TABLES as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('accounting_audit_log')->whereIn('entity_type', self::INTEGRATION_ENTITIES)->delete();
        $db->table('app_config')->whereIn('key', [
            IntegrationOptions::PAYMENT_METHODS,
            IntegrationOptions::SECOND_LANGUAGE,
            IntegrationOptions::SECOND_LANGUAGE_PRINT,
            IntegrationOptions::SECOND_LANGUAGE_CODE,
            IntegrationOptions::API,
            IntegrationOptions::NOTIFICATIONS,
        ])->delete();
    }

    protected function switchOn(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->setConfigValue($key, '1');
        }
    }

    protected function useSecondLanguage(string $code = 'en', bool $print = true): void
    {
        $this->setConfigValue(IntegrationOptions::SECOND_LANGUAGE, '1');
        $this->setConfigValue(IntegrationOptions::SECOND_LANGUAGE_CODE, $code);
        $this->setConfigValue(IntegrationOptions::SECOND_LANGUAGE_PRINT, $print ? '1' : '0');
    }

    protected function integrationManager(): int
    {
        return $this->employeeWithGrants([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);
    }

    protected function keyManager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS], $branchIds);
    }

    /**
     * Every message the application sent to its logger while the action ran, at any level, as one text.
     * The logger is replaced for the length of the action by one that keeps what it is given whatever the
     * threshold of the environment says.
     */
    protected function loggedDuring(callable $action): string
    {
        $logs     = new ReflectionProperty(TestLogger::class, 'op_logs');
        $before   = count($logs->getValue());
        Services::injectMock('logger', new TestLogger(new LoggerConfig()));

        try {
            $action();
        } finally {
            Services::resetSingle('logger');
        }

        return implode("\n", array_map(static fn (array $entry): string => $entry['level'] . ' ' . $entry['message'], array_slice($logs->getValue(), $before)));
    }

    /**
     * Every detail the audit log holds, as one text.
     */
    protected function auditText(): string
    {
        return implode("\n", array_map(static fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE), Database::connect()->table('accounting_audit_log')->get()->getResultArray()));
    }

    /**
     * @return list<string>
     */
    protected function auditActions(string $entityType): array
    {
        return array_column(Database::connect()->table('accounting_audit_log')->select('action')->where('entity_type', $entityType)->orderBy('audit_id', 'ASC')->get()->getResultArray(), 'action');
    }
}
