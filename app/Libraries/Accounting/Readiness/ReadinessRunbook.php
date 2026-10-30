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
                ],
                'notes' => [
                    'Copy writable/ (it holds writable/accounting_attachments) and the .env file next to the dump.',
                    'Restore the dump into a scratch database and run this report against it first: that is the rehearsal.',
                    'Nobody saves a sale or a purchase between the backup and the migrations.',
                ],
            ],
            [
                'title'    => 'Readiness before the migrations',
                'commands' => [
                    'php spark accounting:readiness',
                    'php spark accounting:readiness --json --no-header > readiness-before.json',
                ],
                'notes' => [
                    'Exit code 0 is required to go on. Every FAIL is fixed in the next step, or the owner accepts it in writing.',
                    'The report only reads. Nothing is stamped, moved or decided by it.',
                    'Ask for --no-header together with --json: without it spark prints its own banner before the JSON and the file is not valid JSON.',
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
                    'Stop at the first failure and restore the backup; never edit the migrations table by hand.',
                ],
            ],
            [
                'title'    => 'Post-checks',
                'commands' => [
                    'php spark accounting:readiness',
                    'php spark accounting:bank_accounts',
                    'php spark accounting:check_locks',
                    'php spark accounting:status',
                ],
                'notes' => [
                    'The report must now say post-migration and exit 0; the bank command must report no account outside 1202.',
                    'Compare the supplier balances and their aging with what they were before (E38): they must not change.',
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
                ],
            ],
            [
                'title'    => 'Roll back by restoring the backup',
                'commands' => [
                    'mysql -u USER -p DATABASE < backup-before-release.sql',
                ],
                'notes' => [
                    'down() is not a rollback for the data migrations: LegacyFixedPurchaseDiscount changes what a discount means, BankAccounts does not move accounts back, PurchaseInvoiceDiscount refuses once a purchase uses it.',
                    'Put the code back at the previous release together with the restore, and run this report again against the restored database.',
                ],
            ],
        ];
    }
}
