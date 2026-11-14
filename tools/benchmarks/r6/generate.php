<?php

/**
 * Dev-only generator of a realistic trading business through the real services.
 *
 *   MYSQL_DB_NAME=ospos_test_r6_t10k CI_ENVIRONMENT=development php tools/benchmarks/r6/generate.php setup
 *   MYSQL_DB_NAME=ospos_test_r6_t10k CI_ENVIRONMENT=development php tools/benchmarks/r6/generate.php run 10000
 */

require __DIR__ . '/boot.php';
require __DIR__ . '/Generator.php';

use Tools\Benchmarks\R6\Generator;

$command = $argv[1] ?? '';
$target  = (int) ($argv[2] ?? 0);
$logFile = getenv('R6_LOG') ?: null;

$generator = new Generator();

if ($command === 'setup') {
    $generator->setup();
    fwrite(STDOUT, "setup done\n");
    exit(0);
}

if ($command !== 'run' || $target < 1) {
    fwrite(STDERR, "usage: generate.php setup | run TARGET_DOCUMENTS\n");
    exit(2);
}

$summary = $generator->run($target, static function (array $state, float $seconds, int $newDocs) use ($logFile): void {
    $line = sprintf(
        "%s day=%s docs=%d purchases=%d expenses=%d payments=%d elapsed=%ds rate=%.1f/s mem=%.0fMB peak=%.0fMB load=%s\n",
        gmdate('H:i:s'),
        $state['day'],
        $state['documents'],
        $state['purchases'],
        $state['expenses'],
        $state['payments'],
        $seconds,
        $newDocs / max(1, $seconds),
        memory_get_usage(true) / 1048576,
        memory_get_peak_usage(true) / 1048576,
        implode(' ', array_slice(sys_getloadavg(), 0, 1)),
    );

    $logFile === null ? fwrite(STDOUT, $line) : file_put_contents($logFile, $line, FILE_APPEND);
});

fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT) . "\n");
