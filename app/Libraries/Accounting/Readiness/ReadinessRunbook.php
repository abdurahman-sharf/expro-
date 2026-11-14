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
                    'mysqldump --single-transaction --routines --triggers -u USER -p DATABASE > backup-before-release.sql',
                    'mysql -u USER -p SCRATCH < backup-before-release.sql',
                    'MYSQL_DB_NAME=SCRATCH php spark accounting:readiness --json --no-header > readiness-rehearsal.json',
                ],
                'notes' => [
                    'Copy writable/ (it holds writable/accounting_attachments) and the .env file next to the dump.',
                    'Restore the dump into a scratch database (mysql -u USER -p SCRATCH < backup-before-release.sql) and run this report against it first: that is the rehearsal. Never name the live database there.',
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
                    'W93-CLOCK: the clock of the database and the clock of the shop must name the same day. The invoice, the purchase and the supplier payment take their day from the application (PHP date(), in the zone of the locale settings), but the day of a payment on a sale is stamped by the database (sales_payments.payment_time) and its journal is dated with it: a database a few hours apart puts the payment on another day than its invoice, in another period. The last command shows the zone of the database; align it with the zone of the shop (default-time-zone in the server configuration, or SET GLOBAL time_zone), or set the shop to the zone of the database, and run the report again. A hosting plan that does not let you change the server zone leaves the shop zone as the one to change.',
                    'The php spark commands run in the zone of app.appTimezone (UTC unless the .env file says otherwise), not in the zone of the locale settings; only accounting:generate_recurring and accounting:depreciate apply the zone of the shop. Set app.appTimezone in the .env file to the zone of the shop; W93-CLOCK names the difference.',
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
                    'E38: decide the purchase lines no evidence decides (resolve takes --before, --all-legacy or --none-legacy); the migration stops without changing anything while one is undecided.',
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
                    'The first login after the deploy runs the same migrations by itself: run them here, on purpose, before anybody logs in.',
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
                    'Compare readiness-before.json with readiness-after.json with accounting:readiness_compare (diff, or jq on .checks[].data, does it by hand): E38-SCAN would_mark and would_mark_discount before must equal marked_lines and marked_discount after, so the purchase lines the migration marked are the ones the report named, with the discount they had. The comparison also flags every other value of .checks[].data that changed and a check that turned FAIL or ERROR; it exits 0 when nothing is flagged, 1 when something is and 7 for a file it cannot read, and what it flags somebody explains before the release goes on.',
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
                    'php spark accounting:enable --currency=SAR --start-date=YYYY-MM-DD --fiscal-year-end=YYYY-MM-DD',
                ],
                'notes' => [
                    'Accounting stays disabled on a real business until then. After enabling, the opening stock is posted from the opening balances screen, one location at a time (E26).',
                    'Keep the whole-purchase discount off in production until a separate owner permission (E47, E54).',
                    'Cron, which the code never installs: accounting:generate_recurring once a day at least. accounting:depreciate is run from cron only as the preview, which writes nothing (the posting needs --approve with the fingerprint the preview printed, and is a person\'s act: whether it is ever scheduled is an open question for the owner). accounting:ai_scan and accounting:ai_propose are left unscheduled until the owner switches the agent on: while it is off they refuse and exit 1, so a cron line for them fails at every run.',
                    'The report has no check for fixed assets, e-invoicing, the AI agent, the customer portal or API keys: the migrations switch each off or grant it to nobody, so a half-configured one cannot be found by it. The owner reads the checklist items of the release for each before turning one on.',
                    'Documents saved while accounting was off have no journal. The retroactive posting gives them one: "php spark accounting:backfill --from ... --to ..." is its read-only dry run, and a batch is previewed and approved on the periods screen. Approval stays off (accounting_backfill_enabled) until the owner turns it on, needs the opening balances and the start date, and is never run on a real database without separate permission (E47). Approve a batch before the opening stock of the locations it moves stock in is posted: that opening stock is posted from the shelves, which already hold what those documents moved, and a document saved before it was posted is refused (stock_opened_after).',
                ],
            ],
            [
                'title'    => 'Roll back by restoring the backup',
                'commands' => [
                    'mysqldump --single-transaction -u USER -p DATABASE > state-after-failed-release.sql',
                    'mysql -u USER -p -e "DROP DATABASE DATABASE; CREATE DATABASE DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"',
                    'mysql -u USER -p DATABASE < backup-before-release.sql',
                ],
                'notes' => [
                    'Restore into an empty database: the dump drops and recreates only the tables it holds, so restoring over the live database leaves the tables the release created (accounting_journals and the others) with their rows, and their journals would meet the sales that reuse the same numbers.',
                    'The first command keeps what the failed release left, for the post-mortem; the second needs the right to drop and create the database, and uses the character set and collation of app/Config/Database.php.',
                    'down() is not a rollback for the data migrations: LegacyFixedPurchaseDiscount changes what a discount means, BankAccounts does not move accounts back, PurchaseInvoiceDiscount refuses once a purchase uses it.',
                    'Put the code back at the previous release together with the restore, and run this report again against the restored database.',
                ],
            ],
        ];
    }
}
