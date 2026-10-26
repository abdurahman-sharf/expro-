<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;

/**
 * A lock the database server keeps by name for one connection. It protects only while the
 * connection that asked for it is the connection that does the work, so taking it is not
 * enough: a pooler or proxy that hands out one server connection to two clients, or sends the
 * next statement to another one, leaves the work unprotected without saying so. The lock counts as
 * taken only when the server says that this very connection holds it, and not when this
 * connection already held it for somebody else before it asked.
 */
final class NamedLock
{
    /**
     * How many times this process holds each name, which is what lets the same work take a name
     * again without mistaking its own hold for somebody else's.
     *
     * @var array<string, int>
     */
    private static array $held = [];

    public function __construct(private readonly BaseConnection $db, private readonly int $waitSeconds)
    {
    }

    public function take(string $name): LockOutcome
    {
        $already = self::$held[$name] ?? 0;

        if ($already === 0 && $this->heldByThisConnection($name)) {
            log_message('critical', "The lock {$name} was already held by this database connection before it was asked for. A proxy or pooler is sharing connections, so named locks cannot protect anything.");

            return LockOutcome::Occupied;
        }

        $got = (int) ($this->db->query('SELECT GET_LOCK(?, ?) AS got', [$name, $this->waitSeconds])->getRow()->got ?? 0);

        if ($got !== 1) {
            return LockOutcome::Busy;
        }

        if (! $this->heldByThisConnection($name)) {
            log_message('critical', "The lock {$name} was taken, but not on the connection that runs the work. A proxy or pooler is sending statements to different connections, so named locks cannot protect anything.");

            $this->db->query('SELECT RELEASE_LOCK(?)', [$name]);

            return LockOutcome::NotHeld;
        }

        self::$held[$name] = $already + 1;

        return LockOutcome::Taken;
    }

    public function release(string $name): void
    {
        $left = (self::$held[$name] ?? 1) - 1;

        if ($left > 0) {
            self::$held[$name] = $left;
        } else {
            unset(self::$held[$name]);
        }

        $released = $this->db->query('SELECT RELEASE_LOCK(?) AS released', [$name])->getRow()->released ?? null;

        if ((int) $released !== 1) {
            log_message('critical', "The lock {$name} was not held by this database connection when the work ended.");
        }
    }

    /**
     * Takes a lock that nothing else asks for and lets it go, to say whether the connection to
     * the database keeps named locks at all.
     */
    public function probe(): LockOutcome
    {
        $name  = 'ospos_probe_' . substr(sha1($this->db->getDatabase()), 0, 10) . '_' . bin2hex(random_bytes(4));
        $taken = $this->take($name);

        if ($taken === LockOutcome::Taken) {
            $this->release($name);
        }

        return $taken;
    }

    private function heldByThisConnection(string $name): bool
    {
        return (int) ($this->db->query('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS mine', [$name])->getRow()->mine ?? 0) === 1;
    }
}
