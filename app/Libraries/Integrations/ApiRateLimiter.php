<?php

namespace App\Libraries\Integrations;

use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Counts calls in windows of one minute, in the database so that every server and every process
 * counts the same calls. A bucket is a key or the address a failed call came from.
 */
final class ApiRateLimiter
{
    public const WINDOW_SECONDS = 60;
    private const KEEP_SECONDS  = 3600;

    private BaseConnection $db;
    private Closure $clock;

    public function __construct(?BaseConnection $db = null, ?Closure $clock = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public function hit(string $bucket): int
    {
        $now    = $this->now();
        $window = $this->windowOf($now);
        $table  = $this->db->prefixTable('api_rate_windows');

        $this->db->query(
            "INSERT INTO {$table} (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1",
            [$bucket, $window],
        );

        if ($this->db->affectedRows() === 1) {
            $this->db->table('api_rate_windows')->where('window_start <', $now - self::KEEP_SECONDS)->delete();
        }

        return $this->count($bucket);
    }

    public function count(string $bucket): int
    {
        $row = $this->db->table('api_rate_windows')->select('hits')->where(['bucket' => $bucket, 'window_start' => $this->windowOf($this->now())])->get()->getRow();

        return $row === null ? 0 : (int) $row->hits;
    }

    public function retryAfter(): int
    {
        $now = $this->now();

        return max(1, $this->windowOf($now) + self::WINDOW_SECONDS - $now);
    }

    private function windowOf(int $time): int
    {
        return intdiv($time, self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
    }
}
