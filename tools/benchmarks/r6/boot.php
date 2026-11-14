<?php

/**
 * Boots the application for the R6 performance tools (dev only). Include it from a script run with
 * the database named in MYSQL_DB_NAME; it refuses any database whose name does not start with ospos_test_.
 */

use CodeIgniter\Boot;
use Config\Paths;

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$database = getenv('MYSQL_DB_NAME') ?: '';

if (! str_starts_with($database, 'ospos_test_')) {
    fwrite(STDERR, "Refusing to run: MYSQL_DB_NAME must name a database that starts with ospos_test_\n");
    exit(2);
}

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

define('FCPATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';

$paths = new Paths();

require $paths->systemDirectory . '/Boot.php';
require __DIR__ . '/FakeClock.php';

final class R6Boot extends Boot
{
    public static function start(Paths $paths): void
    {
        static::definePathConstants($paths);

        if (! defined('APP_NAMESPACE')) {
            static::loadConstants();
        }

        static::checkMissingExtensions();
        static::loadDotEnv($paths);
        static::defineEnvironment();
        static::loadEnvironmentBootstrap($paths);
        static::loadCommonFunctions();
        static::loadAutoloader();
        static::setExceptionHandler();
        static::initializeKint();
        static::autoloadHelpers();
        static::initializeCodeIgniter();
    }
}

R6Clock::install(dirname(__DIR__, 3) . '/app');
R6Boot::start($paths);
