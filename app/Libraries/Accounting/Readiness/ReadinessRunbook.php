<?php

namespace App\Libraries\Accounting\Readiness;

/**
 * The order in which an owner database is brought to the new release: back up, check, decide what
 * the checks cannot, migrate, check again, review the grants, and only on the owner's order turn
 * accounting on. Going back is by restoring the backup: the migrations that rewrite data do not
 * undo themselves.
 */
final class ReadinessRunbook
{
    /**
     * @param list<array{version: string, class: string, kind: string, note: string}> $pending The migrations the database has not run.
     *
     * @return list<array{title: string, commands: list<string>, notes: list<string>}>
     */
    public static function steps(array $pending): array
    {
        return [
            [
                'title'    => 'Back up (E54): no migration runs before this exists and restores',
                'commands' => [
                    'tools/ops/backup.sh --keep 14',
                    'tools/ops/backup.sh --verify --keep-scratch --scratch SCRATCH',
                    'MYSQL_DB_NAME=SCRATCH php spark accounting:readiness --json --no-header > readiness-rehearsal.json',
                ],
                'notes' => [
                    'tools/ops/backup.sh writes mysqldump --single-transaction --routines --triggers of the database, an archive of writable/ (it holds writable/accounting_attachments; cache, session and logs are left out), a copy of the .env file (mode 0600) and a manifest (time, git commit, rows and checksum of every table, SHA-256 of every file) into a dated folder under backups/, whole or not at all, and keeps the newest 14. It reads the database from MYSQL_HOST_NAME, MYSQL_USERNAME, MYSQL_PASSWORD and MYSQL_DB_NAME or from the .env file, and never prints the password. Without the tool: mysqldump --single-transaction --routines --triggers -u USER -p DATABASE > backup-before-release.sql, and copy writable/ and the .env file next to the dump.',
                    'Copy the dated folder off the server after every backup (another machine or a storage service): a backup on the disk of the server it protects is lost with the server. The folder holds a copy of the .env file, so it stays private.',
                    'Restore the dump into a scratch database (the second command, or mysql -u USER -p SCRATCH < backup-before-release.sql) and run this report against it first: that is the rehearsal. Never name the live database there. The second command compares CHECKSUM TABLE and the row count of every table of the restored copy with the manifest and with the database now, and keeps the scratch database (--keep-scratch) for the report.',
                    'MYSQL_DB_NAME makes the command read the scratch database and not the one of the .env file (app/Config/Database.php): the rehearsal is a restore that was run and a report taken on the restored copy, so keep readiness-rehearsal.json and the date of the restore next to the dump; nothing in this repository records that a restore was ever rehearsed.',
                    'Nobody saves a sale or a purchase between the backup and the migrations.',
                ],
            ],
            [
                'title'    => 'Readiness before the migrations',
                'commands' => [
                    'php spark accounting:readiness',
                    'php spark accounting:readiness --json --no-header > readiness-before.json',
                    'mysql -u USER -p -e "SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone, NOW(), UTC_TIMESTAMP()"',
                ],
                'notes' => [
                    'Exit code 0 is required to go on. Every FAIL is fixed in the next step, or the owner accepts it in writing.',
                    'The report only reads. Nothing is stamped, moved or decided by it.',
                    'Ask for --no-header together with --json: without it spark prints its own banner before the JSON and the file is not valid JSON.',
                    'W93-CLOCK: the clock of the database and the clock of the shop must name the same day. The invoice, the purchase and the supplier payment take their day from the application (PHP date(), in the zone of the locale settings), but the day of a payment on a sale is stamped by the database (sales_payments.payment_time) and its journal is dated with it: a database a few hours apart puts the payment on another day than its invoice, in another period. The last command shows the zone of the database. On a database that holds no sale or purchase yet, align it with the zone of the shop (default-time-zone in the server configuration, or SET GLOBAL time_zone), or set the shop to the zone of the database, and run the report again. On a database that already holds history, do not change the zone of the database server on its own: sales.sale_time, receivings.receiving_time and sales_payments.payment_time are TIMESTAMP columns, which the database keeps in UTC and shows in the zone of the session, so a new server zone moves the time every saved row shows (a sale saved late in the evening shows as the next day, and an edit or a repost of it dates its journal on the shifted day, perhaps in another period): convert the saved times (CONVERT_TZ) on the rehearsal copy first and have the accountant check them. Setting the zone of the shop to the one of the database leaves the saved rows alone, but a new document then takes its day from the clock of the database, so the first hours of the shop day carry the day before. A hosting plan that does not let you change the server zone leaves the shop zone as the one to change.',
                    'The php spark commands run in the zone of app.appTimezone (UTC unless the .env file says otherwise), not in the zone of the locale settings; only accounting:generate_recurring and accounting:depreciate apply the zone of the shop. Set app.appTimezone in the .env file to the zone of the shop; W93-CLOCK names the difference.',
                    'ADMIN-DEFAULT-PASSWORD fails while any account still has the password the installer gave it, which is published with the project: change it from the employee form before the site can be reached from outside. FIRST-RUN-SETTINGS only asks for review: it lists the company texts, formats, tax number and numbering that the installer wrote and that print on every document (checklist in docs/ui-redesign/deploy-checklist.md).',
                    'A production .env file sets CI_ENVIRONMENT=production, logger.threshold (leave it out, or 4: 0 turns the log off and 9 writes everything), app.allowedHostnames, app.appTimezone, FORCE_HTTPS=true behind a proxy that ends TLS, app.forceGlobalSecureRequests=true and cookie.secure=true on https; writable/ belongs to the web user and .env is mode 0600 (section 5 of docs/accounting/readiness-runbook.md).',
                ],
            ],
            [
                'title'    => 'Decide what the report lists as FAIL',
                'commands' => [
                    'php spark accounting:purchase_discount',
                    'php spark accounting:purchase_discount resolve --before "YYYY-MM-DD HH:MM:SS"',
                    'php spark accounting:stamp_payments',
                    'php spark accounting:bank_accounts',
                ],
                'notes' => [
                    'E38: decide the purchase lines no evidence decides (resolve takes --before, --all-legacy or --none-legacy); the migration stops without changing anything while one is undecided. The login page checks it before the first migration: while a line is undecided it answers 503 with "... cannot be told apart ... Run php spark accounting:purchase_discount resolve" and migrates nothing (it used to run the first dozens of migrations, fail with a 500 page and leave the database half migrated).',
                    'accounting:stamp_payments and accounting:bank_accounts read tables that the migrations create: before step 4 they print "Not available yet" with "run php spark migrate -n App first" and exit 1, and they clear the FAIL rows that the report shows after the migrations (step 5).',
                    'E41: stamping sets only the treasury of a payment, where its account equals the account of the payment journal. The owner orders it: "php spark accounting:stamp_payments --apply" or "php spark accounting:readiness --apply".',
                    'E40: bank accounts move under 1202 by the migration or by "php spark accounting:bank_accounts --move"; the bank name and account number of each are completed from the treasuries screen.',
                ],
            ],
            [
                'title'    => 'Run the migrations in this order',
                'commands' => ['php spark migrate -n App'],
                'notes'    => [
                    ...($pending === [] ? ['No migration is pending.'] : array_map(
                        static fn (array $migration): string => $migration['version'] . ' ' . $migration['class'] . ' [' . strtoupper($migration['kind']) . ']' . ($migration['note'] === '' ? '' : ': ' . $migration['note']),
                        $pending,
                    )),
                    'DATA: rewrites or classifies existing rows or adds to the chart. HEAVY: alters a large table, so run it in a quiet hour. The order is the order of the version numbers and each step depends on the ones before it.',
                    'The first request after the deploy would run the same migrations by itself: the login page starts them with no account, and POST /migrate with one. Run them here, on purpose, before the site is opened, and keep it closed (the web server stopped, or a maintenance page) until step 5 is done; a web request is also cut by the time limit of the gateway (86 seconds were measured on ten times the data of a real shop). A web request that starts them first checks the E38 decision, then takes a lock of the database server (GET_LOCK) and waits 3 seconds for it: two requests never run them together, and the second sees "Another request is migrating this database" with status 503. An error becomes a sentence that names writable/logs and "php spark migrate -n App" (no stack trace, no empty 500 page), but the database may be half migrated: see "How to know the migrations half ran" in section 7 of docs/accounting/readiness-runbook.md.',
                    'No run of these migrations on MySQL 5.7 or MariaDB 10.5 is recorded in the project documents (the CI matrix names those images, and its result is not recorded here): rehearse the migrations in step 1 on a scratch database served by the same server version as the live one.',
                    'Stop at the first failure and restore the backup; never edit the migrations table by hand.',
                ],
            ],
            [
                'title'    => 'Post-checks',
                'commands' => [
                    'php spark accounting:readiness',
                    'php spark accounting:readiness --json --no-header > readiness-after.json',
                    'php spark accounting:readiness_compare readiness-before.json readiness-after.json',
                    'php spark accounting:bank_accounts',
                    'php spark accounting:check_locks',
                    'php spark accounting:status',
                ],
                'notes' => [
                    'The report must now say post-migration and exit 0; the bank command must report no account outside 1202.',
                    'Compare readiness-before.json with readiness-after.json with accounting:readiness_compare (diff, or jq on .checks[].data, does it by hand): E38-SCAN would_mark and would_mark_discount before must equal marked_lines and marked_discount after, so the purchase lines the migration marked are the ones the report named, with the discount they had. The comparison also flags every other value of .checks[].data that changed and a check that turned FAIL or ERROR; it exits 0 when nothing is flagged, 1 when something is and 7 for a file it cannot read, and what it flags somebody explains before the release goes on. A second report that still says pre-migration, and two files that are one report, are flagged too (exit 1): they cannot show what the migrations changed, so take the reports again. Put the two file names before the options (accounting:readiness_compare readiness-before.json readiness-after.json --json --no-header): an option placed first takes the next file name for its value.',
                    'TIE-CUSTOMERS and TIE-SUPPLIERS keep the aging total of every party in data.aging_totals: a migration may move an amount from one bucket to another and never change what a party owes or is owed, so a party whose total moved is flagged. A database whose books did not exist before has both checks SKIPPED in the first report and nothing to compare them with.',
                    'TIE-SUPPLIERS and TIE-CUSTOMERS are SKIPPED before the migrations while the books do not exist yet, and must now PASS: that is the evidence that every supplier and customer account ties to its documents, its credit and its aging.',
                    'Open the balances between branches and the treasuries screen and look at what the report listed for review.',
                ],
            ],
            [
                'title'    => 'Review who holds the sensitive permissions (OWNER-GRANTS)',
                'commands' => [],
                'notes'    => [
                    'The report lists the holders of each sensitive permission. The administrator grants and removes them by hand from the employee form; no migration grants them.',
                ],
            ],
            [
                'title'    => 'Turn accounting on only when the owner orders it (E26)',
                'commands' => [
                    'php spark accounting:fill_accounts',
                    'php spark accounting:enable --currency SAR --start-date YYYY-MM-DD --fiscal-year-end YYYY-MM-DD',
                ],
                'notes' => [
                    'Accounting stays disabled on a real business until then. After enabling, the opening stock is posted from the opening balances screen, one location at a time (E26).',
                    'Keep the whole-purchase discount off in production until a separate owner permission (E47, E54).',
                    'Cron, which the code never installs: accounting:generate_recurring once a day at least. accounting:depreciate is run from cron only as the preview, which writes nothing (the posting needs --approve with the fingerprint the preview printed, and is a person\'s act: whether it is ever scheduled is an open question for the owner). accounting:ai_scan and accounting:ai_propose are left unscheduled until the owner switches the agent on: while it is off they refuse and exit 1, so a cron line for them fails at every run.',
                    'The backup is a cron line too, at a quiet hour: 17 3 * * * cd /path/to/expro && tools/ops/backup.sh --keep 14 >> /var/log/expro-backup.log 2>&1 (cron has no environment: the tool reads the .env file), and a weekly "tools/ops/backup.sh --verify" restores the newest backup into a scratch database and compares every table. Copy the folder off the server every time.',
                    'The report has no check for fixed assets, e-invoicing, the AI agent, the customer portal or API keys: the migrations switch each off or grant it to nobody, so a half-configured one cannot be found by it. The owner reads the checklist items of the release for each before turning one on.',
                    'Documents saved while accounting was off have no journal. The retroactive posting gives them one: "php spark accounting:backfill --from ... --to ..." is its read-only dry run, and a batch is previewed and approved on the periods screen. Approval stays off (accounting_backfill_enabled) until the owner turns it on, needs the opening balances and the start date, and is never run on a real database without separate permission (E47). Approve a batch before the opening stock of the locations it moves stock in is posted: that opening stock is posted from the shelves, which already hold what those documents moved, and a document saved before it was posted is refused (stock_opened_after).',
                ],
            ],
            [
                'title'    => 'Roll back by restoring the backup',
                'commands' => [
                    'tools/ops/backup.sh --dir state-after-failed-release',
                    'mysql -u USER -p -e "DROP DATABASE DATABASE; CREATE DATABASE DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"',
                    'tools/ops/restore.sh --yes-empty-database DATABASE backups/YYYYMMDD-HHMMSS',
                ],
                'notes' => [
                    'Restore into an EMPTY database only: the dump drops and recreates only the tables it holds, so restoring over the live database leaves the tables the release created (accounting_journals and the others) with their rows, and their journals would meet the sales that reuse the same numbers; worse, the next migration then fails half way (seen on a copy: migration 59, Unknown column invoice_date, 58 of 116 applied). tools/ops/restore.sh refuses a database that holds anything, wants the name of the empty database written out (--yes-empty-database), checks the SHA-256 of every file before it writes, loads the dump, compares CHECKSUM TABLE and the row count of every table with the manifest, puts writable/ back, leaves the live .env alone and prints the readiness command to run.',
                    'The first command keeps what the failed release left, for the post-mortem; the second needs the right to drop and create the database, and uses the character set and collation of app/Config/Database.php. Stop the site before the second command. Without the tools: mysqldump --single-transaction -u USER -p DATABASE > state-after-failed-release.sql first, and mysql -u USER -p DATABASE < backup-before-release.sql after the drop.',
                    'down() is not a rollback for the data migrations: LegacyFixedPurchaseDiscount changes what a discount means, BankAccounts does not move accounts back, PurchaseInvoiceDiscount refuses once a purchase uses it.',
                    'Put the code back at the previous release together with the restore (the manifest names the git commit of the backup), and run this report again against the restored database.',
                ],
            ],
        ];
    }
}
