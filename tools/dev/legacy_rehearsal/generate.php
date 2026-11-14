<?php

declare(strict_types=1);

use Tools\Dev\LegacyRehearsal\LegacyDataGenerator;

require __DIR__ . '/LegacyDataGenerator.php';

const USAGE = <<<'TEXT'
usage: MYSQL_DB_NAME=ospos_test_x php tools/dev/legacy_rehearsal/generate.php [options]

Fills a migrated, empty legacy OSPOS schema (migrations before 20261021000000 only) with seeded,
realistic legacy data. It refuses to run unless the database name starts with ospos_test_, the
accounting tables are absent and the sales, items, customers and receivings tables are empty.

  --seed=N         random seed (default 20261114)
  --customers=N    default 2000
  --suppliers=N    default 200
  --items=N        default 3000
  --sales=N        default 25000
  --receivings=N   default 3000
  --expenses=N     default 1500
  --cashups=N      default 300
  --giftcards=N    default 150
  --from=Y-m-d     first day of the history (default 2024-01-01)
  --to=Y-m-d       last day of the history (default 2026-11-10)
  --prefix=P       table prefix (default ospos_)
  --fk-orphans     also write rows that break foreign keys (written with the checks off)

Connection: MYSQL_HOST_NAME, MYSQL_USERNAME, MYSQL_PASSWORD and MYSQL_DB_NAME, falling back to
the database.tests.* entries of the .env file at the repository root.
TEXT;

function envFileValue(string $key): ?string
{
    $file = dirname(__DIR__, 3) . '/.env';

    if (! is_file($file)) {
        return null;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^' . preg_quote($key, '/') . "\\s*=\\s*'?(.*?)'?\\s*$/", $line, $match) === 1) {
            return $match[1];
        }
    }

    return null;
}

$options = [
    'seed' => 20261114,
    'customers' => 2000,
    'suppliers' => 200,
    'items' => 3000,
    'sales' => 25000,
    'receivings' => 3000,
    'expenses' => 1500,
    'cashups' => 300,
    'giftcards' => 150,
    'from' => '2024-01-01',
    'to' => '2026-11-10',
    'prefix' => 'ospos_',
    'fkOrphans' => false,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, USAGE . "\n");
        exit(0);
    }

    if ($argument === '--fk-orphans') {
        $options['fkOrphans'] = true;

        continue;
    }

    if (preg_match('/^--([a-z]+)=(.+)$/', $argument, $match) !== 1 || ! array_key_exists($match[1], $options)) {
        fwrite(STDERR, "Unknown option: {$argument}\n\n" . USAGE . "\n");
        exit(7);
    }

    $options[$match[1]] = is_int($options[$match[1]]) ? (int) $match[2] : $match[2];
}

$host = getenv('MYSQL_HOST_NAME') ?: (envFileValue('database.tests.hostname') ?? '127.0.0.1');
$user = getenv('MYSQL_USERNAME') ?: (envFileValue('database.tests.username') ?? 'admin');
$password = getenv('MYSQL_PASSWORD') ?: (envFileValue('database.tests.password') ?? '');
$database = getenv('MYSQL_DB_NAME') ?: '';

if (! str_starts_with($database, 'ospos_test_')) {
    fwrite(STDERR, "Refusing to run: MYSQL_DB_NAME must be set and start with ospos_test_ (got '{$database}').\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli($host, $user, $password, $database);
$connection->set_charset('utf8mb4');

$started = microtime(true);
$stats = (new LegacyDataGenerator($connection, $options))->run();
ksort($stats);

foreach ($stats as $name => $count) {
    fwrite(STDOUT, str_pad($name, 24) . $count . "\n");
}

fwrite(STDOUT, sprintf("done in %.1f s, seed %d\n", microtime(true) - $started, $options['seed']));
