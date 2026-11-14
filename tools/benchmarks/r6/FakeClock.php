<?php

/**
 * A clock the benchmark tools can set. PHP code that calls date(), time() or strtotime() without a
 * leading backslash inside a namespace picks up a function of the same name defined in that namespace,
 * so one definition per namespace of the application is enough to give the application a past day
 * without changing a line of it. The database clock is set per connection with SET timestamp.
 */
final class R6Clock
{
    private static ?int $now = null;

    public static function now(): int
    {
        return self::$now ?? \time();
    }

    public static function set(?int $timestamp): void
    {
        self::$now = $timestamp;
    }

    public static function setDatabase(\CodeIgniter\Database\BaseConnection $db): void
    {
        $offset  = (new \DateTimeZone(date_default_timezone_get()))->getOffset(new \DateTimeImmutable('@' . self::now()));
        $sign    = $offset < 0 ? '-' : '+';
        $offset  = abs($offset);
        $db->query(sprintf("SET SESSION time_zone = '%s%02d:%02d', timestamp = %d", $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60), self::now()));
    }

    public static function install(string $appDirectory): void
    {
        $env = getenv('R6_NOW');

        if (is_string($env) && $env !== '') {
            self::$now = (int) strtotime($env);
        }

        $cache      = sys_get_temp_dir() . '/r6-clock-namespaces-' . md5($appDirectory) . '.json';
        $namespaces = is_file($cache) && getenv('R6_REFRESH') !== '1' ? array_fill_keys((array) json_decode((string) file_get_contents($cache), true), true) : [];

        if ($namespaces === []) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDirectory, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $match) === 1 && preg_match('/(^|[^>:\\\\\w$])(date|gmdate|time|strtotime)\(/m', $source) === 1) {
                    $namespaces[$match[1]] = true;
                }
            }

            file_put_contents($cache, json_encode(array_keys($namespaces)));
        }

        foreach (array_keys($namespaces) as $namespace) {
            eval(<<<PHP
                namespace {$namespace} {
                    if (! function_exists('{$namespace}\\date')) {
                        function date(string \$format, ?int \$timestamp = null): string { return \\date(\$format, \$timestamp ?? \\R6Clock::now()); }
                        function gmdate(string \$format, ?int \$timestamp = null): string { return \\gmdate(\$format, \$timestamp ?? \\R6Clock::now()); }
                        function time(): int { return \\R6Clock::now(); }
                        function strtotime(string \$datetime, ?int \$baseTimestamp = null): int|false { return \\strtotime(\$datetime, \$baseTimestamp ?? \\R6Clock::now()); }
                    }
                }
                PHP);
        }
    }
}
