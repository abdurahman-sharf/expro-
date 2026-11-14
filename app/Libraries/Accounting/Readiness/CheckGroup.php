<?php

namespace App\Libraries\Accounting\Readiness;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * A set of related checks. Each runs on its own: one that needs what the schema does not have yet
 * is skipped, and one that fails to run is reported as an error, so that the report is always whole.
 */
abstract class CheckGroup
{
    protected SchemaProbe $schema;

    public function __construct(protected BaseConnection $db)
    {
        $this->schema = new SchemaProbe($db);
    }

    /**
     * @return list<CheckResult>
     */
    abstract public function run(): array;

    /**
     * @param list<string>            $requires Tables and "table.column" pairs the check reads.
     * @param callable(): CheckResult $work
     */
    protected function check(string $id, string $ref, string $title, array $requires, callable $work): CheckResult
    {
        $missing = $this->schema->missing($requires);

        if ($missing !== []) {
            return CheckResult::skipped('Not checked: the database does not have ' . implode(', ', $missing) . ' yet (a migration is pending).', ['missing' => $missing])->named($id, $ref, $title);
        }

        try {
            return $work()->named($id, $ref, $title);
        } catch (Throwable $failure) {
            return CheckResult::error($failure)->named($id, $ref, $title);
        }
    }

    protected function shopZoneSetting(): string
    {
        $row = $this->db->table('app_config')->select('value')->where('key', 'timezone')->get()->getRow();

        return trim((string) ($row->value ?? ''));
    }

    protected function isZone(string $zone): bool
    {
        try {
            new DateTimeZone($zone);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    protected function shopToday(): string
    {
        $setting = $this->shopZoneSetting();
        $zone    = $setting !== '' && $this->isZone($setting) ? $setting : date_default_timezone_get();

        return (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('Y-m-d');
    }
}
