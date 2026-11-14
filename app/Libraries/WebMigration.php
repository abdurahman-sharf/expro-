<?php

namespace App\Libraries;

use Throwable;

/**
 * The migrations as a web request starts them (the login page, and the migrate address): only when
 * nothing needs the operator first, only one request at a time, and an error is a message for the
 * page and a line in the log, never a stack trace or an empty error page. A request that is turned
 * away is told why in a sentence that names what to do.
 */
final class WebMigration
{
    public const BUSY = 'Another request is migrating this database: wait for it to finish, then reload this page.';

    public const FAILED = 'The migration stopped before it was finished and the database may be half migrated: do not open the site. See writable/logs, run "php spark migrate -n App" to read the error, and restore the backup if it cannot be fixed (docs/accounting/readiness-runbook.md, section 7).';

    public function __construct(private ?MigrationLock $lock = null, private ?MigrationPreflight $preflight = null)
    {
        $this->lock      ??= new MigrationLock();
        $this->preflight ??= new MigrationPreflight();
    }

    /**
     * @param callable(): mixed $latest The migrations to run.
     *
     * @return string|null Null when they ran, otherwise the sentence for the page.
     */
    public function run(callable $latest): ?string
    {
        $refusal = $this->preflight->refusal();

        if ($refusal !== null) {
            return $refusal;
        }

        try {
            return $this->lock->run($latest) ? null : self::BUSY;
        } catch (Throwable $failure) {
            log_message('error', 'Migration failed: ' . $failure::class . ': ' . $failure->getMessage());

            return self::FAILED;
        }
    }
}
