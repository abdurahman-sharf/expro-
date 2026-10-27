<?php

namespace Tests\Support;

use Config\Database;

trait WorkerRequestTrait
{
    /**
     * Starts one request of tests/Support/RaceWorker.php in a process of its own and lets it go at once.
     *
     * @return array{process: resource, pipes: array<int, resource>, files: list<string>}
     */
    protected function startWorkerRequest(string $method, string ...$arguments): array
    {
        $ready = tempnam(sys_get_temp_dir(), 'worker_ready_');
        $go    = tempnam(sys_get_temp_dir(), 'worker_go_');
        unlink($ready);
        unlink($go);

        $environment = array_merge(array_filter($_SERVER, static fn ($value): bool => is_scalar($value)), ['RACE_READY_FILE' => $ready, 'RACE_GO_FILE' => $go]);
        $process     = proc_open([PHP_BINARY, __DIR__ . '/RaceWorker.php', $method, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, $environment);

        $this->assertIsResource($process);
        fclose($pipes[0]);

        $deadline = microtime(true) + 10.0;

        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(1000);
        }

        $this->assertFileExists($ready, 'The request did not start');
        file_put_contents($go, '1');

        return ['process' => $process, 'pipes' => $pipes, 'files' => [$ready, $go]];
    }

    /**
     * Waits until a statement that locks rows has been held up for a second by a lock another
     * connection holds, which only a request of another process can be doing while this one holds it.
     */
    protected function waitUntilARequestWaitsForARowLock(): void
    {
        $db       = Database::connect();
        $own      = (int) $db->query('SELECT CONNECTION_ID() AS id')->getRow()->id;
        $deadline = microtime(true) + 15.0;

        do {
            foreach ($db->query('SHOW PROCESSLIST')->getResultArray() as $thread) {
                if ((int) $thread['Id'] !== $own && str_contains((string) $thread['Info'], 'FOR UPDATE') && (int) $thread['Time'] >= 1) {
                    return;
                }
            }

            usleep(50000);
        } while (microtime(true) < $deadline);

        $this->fail('No request ever waited for a row lock');
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>, files: list<string>} $request
     *
     * @return array{exit: int, output: string, error: string}
     */
    protected function finishWorkerRequest(array $request): array
    {
        $output   = '';
        $error    = '';
        $deadline = microtime(true) + 20.0;

        stream_set_blocking($request['pipes'][1], false);
        stream_set_blocking($request['pipes'][2], false);

        do {
            $output .= stream_get_contents($request['pipes'][1]);
            $error  .= stream_get_contents($request['pipes'][2]);
            $status  = proc_get_status($request['process']);
            usleep(5000);
        } while ($status['running'] && microtime(true) < $deadline);

        $output .= stream_get_contents($request['pipes'][1]);
        $error  .= stream_get_contents($request['pipes'][2]);
        $exit    = $status['running'] ? -1 : $status['exitcode'];

        if ($status['running']) {
            proc_terminate($request['process']);
        }

        fclose($request['pipes'][1]);
        fclose($request['pipes'][2]);
        proc_close($request['process']);

        foreach ($request['files'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        return ['exit' => $exit, 'output' => trim($output), 'error' => $error];
    }
}
