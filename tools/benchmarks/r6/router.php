<?php

/**
 * Router for PHP's built-in server (dev only): exports the environment to $_SERVER, moves the clock of the
 * application to R6_NOW when it is set, and appends one JSON line per request to R6_HTTP_LOG with the
 * time, the peak memory, the status, the number of statements and whether the script died of its memory limit.
 *
 *   CI_ENVIRONMENT=production MYSQL_DB_NAME=ospos_test_r6_t10k R6_NOW="2025-01-15 20:00:00" ALLOWED_HOSTNAMES=127.0.0.1 \
 *   php -d variables_order=EGPCS -d memory_limit=256M -S 127.0.0.1:8086 -t public tools/benchmarks/r6/router.php
 */

foreach (['CI_ENVIRONMENT', 'MYSQL_DB_NAME', 'ALLOWED_HOSTNAMES', 'R6_NOW', 'R6_HTTP_LOG'] as $name) {
    $value = getenv($name);

    if ($value !== false) {
        $_SERVER[$name] = $value;
        $_ENV[$name]    = $value;
    }
}

$database = (string) ($_SERVER['MYSQL_DB_NAME'] ?? '');

if (! str_starts_with($database, 'ospos_test_')) {
    http_response_code(500);
    echo 'MYSQL_DB_NAME must start with ospos_test_';

    return true;
}

$uri  = urldecode(parse_url('https://x.test' . $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
$path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . ltrim($uri, '/');

if ($uri !== '/' && (is_file($path) || is_dir($path))) {
    return false;
}

require_once __DIR__ . '/FakeClock.php';

R6Clock::install(dirname(__DIR__, 3) . '/app');

$log = $_SERVER['R6_HTTP_LOG'] ?? null;

register_shutdown_function(static function () use ($log): void {
    if ($log === null) {
        return;
    }

    $error     = error_get_last();
    $fatal     = $error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true);
    $questions = null;

    try {
        if (class_exists(\Config\Database::class, false)) {
            $result    = \Config\Database::connect()->query("SHOW SESSION STATUS LIKE 'Questions'");
            $questions = $result === false ? null : (int) $result->getRow()->Value - 1;
        }
    } catch (\Throwable) {
    }

    file_put_contents($log, json_encode([
        'uri'       => $_SERVER['REQUEST_URI'],
        'status'    => http_response_code(),
        'seconds'   => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3),
        'peak_mb'   => round(memory_get_peak_usage(false) / 1048576, 1),
        'questions' => $questions,
        'fatal'     => $fatal ? substr($error['message'], 0, 160) : null,
        'limit'     => ini_get('memory_limit'),
        'load'      => round(sys_getloadavg()[0], 2),
    ]) . "\n", FILE_APPEND | LOCK_EX);
});

$_SERVER['SCRIPT_NAME'] = '/index.php';

require_once $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'index.php';
