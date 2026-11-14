<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Races two raw SQL statements against each other over two independent
 * mysqli connections, using MYSQLI_ASYNC so both are in flight before either
 * resolves. This exercises real database row-level locking instead of
 * simulating concurrency with two sequential calls on one connection.
 */
trait ConcurrentDbRaceTrait
{
    protected function raceWorkerScript(): string
    {
        return __DIR__ . '/RaceWorker.php';
    }

    /**
     * What a worker that is no longer running said as it went, or null while it runs. A worker that ends before
     * its barrier never writes its ready file, and its own words are the only explanation of why.
     *
     * @param resource               $process
     * @param array<int, resource> $pipes
     */
    private function raceWorkerEndedEarly($process, array $pipes): ?string
    {
        $status = proc_get_status($process);

        if ($status['running']) {
            return null;
        }

        return 'it ended with exit code ' . $status['exitcode'] . ': ' . trim(stream_get_contents($pipes[2]) . ' ' . stream_get_contents($pipes[1]));
    }

    /**
     * @return array{0: int, 1: int} affected_rows for [sql1, sql2]
     */
    protected function raceTwoUpdates(string $sql1, string $sql2): array
    {
        $config = config('Database')->tests;

        $link1 = mysqli_connect($config['hostname'], $config['username'], $config['password'], $config['database'], $config['port']);
        $link2 = mysqli_connect($config['hostname'], $config['username'], $config['password'], $config['database'], $config['port']);

        mysqli_query($link1, $sql1, MYSQLI_ASYNC);
        mysqli_query($link2, $sql2, MYSQLI_ASYNC);

        try {
            $pending  = [$link1, $link2];
            $deadline = hrtime(true) + 10_000_000_000;

            while ($pending !== []) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('mysqli_poll failed to resolve pending queries before deadline');
                }

                $read = $pending;
                $error = $pending;
                $reject = $pending;

                $ready = mysqli_poll($read, $error, $reject, 5);

                if ($ready === false) {
                    throw new RuntimeException('mysqli_poll returned false');
                }

                foreach (array_merge($read, $error, $reject) as $link) {
                    if (mysqli_reap_async_query($link) === false) {
                        throw new RuntimeException('mysqli_reap_async_query failed: ' . mysqli_error($link));
                    }

                    $pending = array_filter($pending, static fn ($pendingLink) => $pendingLink !== $link);
                }
            }

            $affectedRows1 = mysqli_affected_rows($link1);
            $affectedRows2 = mysqli_affected_rows($link2);
        } finally {
            mysqli_close($link1);
            mysqli_close($link2);
        }

        return [$affectedRows1, $affectedRows2];
    }

    /**
     * Races two model-method invocations against each other by running each
     * in its own PHP CLI process (tests/Support/RaceWorker.php), so the two
     * calls hit the database over genuinely separate connections at the same
     * time instead of running sequentially on one connection.
     *
     * @return array{0: bool, 1: bool} return value of the model method for [call1, call2]
     */
    protected function raceTwoProcesses(string $method, array $args1, array $args2): array
    {
        $workerScript = $this->raceWorkerScript();
        $php          = PHP_BINARY;

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command1 = array_merge([$php, $workerScript, $method], array_map('strval', $args1));
        $command2 = array_merge([$php, $workerScript, $method], array_map('strval', $args2));

        $readyFile1 = tempnam(sys_get_temp_dir(), 'race_ready_');
        $readyFile2 = tempnam(sys_get_temp_dir(), 'race_ready_');
        $goFile     = tempnam(sys_get_temp_dir(), 'race_go_');
        unlink($readyFile1);
        unlink($readyFile2);
        unlink($goFile);

        $baseEnv = array_filter($_SERVER, static fn ($value) => is_scalar($value));
        $env1    = array_merge($baseEnv, ['RACE_READY_FILE' => $readyFile1, 'RACE_GO_FILE' => $goFile]);
        $env2    = array_merge($baseEnv, ['RACE_READY_FILE' => $readyFile2, 'RACE_GO_FILE' => $goFile]);

        $process1 = proc_open($command1, $descriptorSpec, $pipes1, ROOTPATH, $env1);
        $process2 = proc_open($command2, $descriptorSpec, $pipes2, ROOTPATH, $env2);

        fclose($pipes1[0]);
        fclose($pipes2[0]);

        stream_set_blocking($pipes1[1], false);
        stream_set_blocking($pipes1[2], false);
        stream_set_blocking($pipes2[1], false);
        stream_set_blocking($pipes2[2], false);

        try {
            $deadline = microtime(true) + 5.0;

            while (!file_exists($readyFile1) || !file_exists($readyFile2)) {
                $ended = [];

                foreach ([1 => [$process1, $pipes1, $readyFile1], 2 => [$process2, $pipes2, $readyFile2]] as $call => [$process, $pipes, $readyFile]) {
                    $words = $this->raceWorkerEndedEarly($process, $pipes);

                    if ($words !== null && !file_exists($readyFile)) {
                        $ended[] = 'call ' . $call . ' ' . $words;
                    }
                }

                if ($ended !== [] || microtime(true) >= $deadline) {
                    $status1 = proc_get_status($process1);
                    $status2 = proc_get_status($process2);

                    if ($status1['running']) {
                        proc_terminate($process1);
                    }

                    if ($status2['running']) {
                        proc_terminate($process2);
                    }

                    fclose($pipes1[1]);
                    fclose($pipes1[2]);
                    fclose($pipes2[1]);
                    fclose($pipes2[2]);
                    proc_close($process1);
                    proc_close($process2);

                    $waiting = array_filter([
                        !file_exists($readyFile1) ? 'call 1' : null,
                        !file_exists($readyFile2) ? 'call 2' : null,
                    ]);

                    throw new RuntimeException('race_worker.php failed to reach readiness barrier: ' . implode(', ', $waiting) . ($ended === [] ? '' : ' (' . implode('; ', $ended) . ')'));
                }

                usleep(1000);
            }

            file_put_contents($goFile, '1');

            $output1 = '';
            $error1  = '';
            $output2 = '';
            $error2  = '';

            $drain = static function () use ($pipes1, $pipes2, &$output1, &$error1, &$output2, &$error2): void {
                $output1 .= stream_get_contents($pipes1[1]);
                $error1  .= stream_get_contents($pipes1[2]);
                $output2 .= stream_get_contents($pipes2[1]);
                $error2  .= stream_get_contents($pipes2[2]);
            };

            $completionDeadline = microtime(true) + 10.0;
            $exitCode1          = null;
            $exitCode2          = null;

            while (true) {
                $drain();

                $status1 = proc_get_status($process1);
                $status2 = proc_get_status($process2);

                if (!$status1['running'] && $exitCode1 === null) {
                    $exitCode1 = $status1['exitcode'];
                }

                if (!$status2['running'] && $exitCode2 === null) {
                    $exitCode2 = $status2['exitcode'];
                }

                if (!$status1['running'] && !$status2['running']) {
                    $drain();
                    break;
                }

                if (microtime(true) >= $completionDeadline) {
                    if ($status1['running']) {
                        proc_terminate($process1);
                    }

                    if ($status2['running']) {
                        proc_terminate($process2);
                    }

                    fclose($pipes1[1]);
                    fclose($pipes1[2]);
                    fclose($pipes2[1]);
                    fclose($pipes2[2]);
                    proc_close($process1);
                    proc_close($process2);

                    $exitCode1 ??= -1;
                    $exitCode2 ??= -1;

                    $stillRunning = array_filter([
                        $status1['running'] ? 'call 1' : null,
                        $status2['running'] ? 'call 2' : null,
                    ]);

                    throw new RuntimeException('race_worker.php failed to complete before deadline: ' . implode(', ', $stillRunning));
                }

                usleep(1000);
            }

            fclose($pipes1[1]);
            fclose($pipes1[2]);
            proc_close($process1);

            fclose($pipes2[1]);
            fclose($pipes2[2]);
            proc_close($process2);
        } finally {
            foreach ([$readyFile1, $readyFile2, $goFile] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        if ($exitCode1 !== 0) {
            throw new RuntimeException("race_worker.php (call 1) exited with {$exitCode1}: {$error1}");
        }

        if ($exitCode2 !== 0) {
            throw new RuntimeException("race_worker.php (call 2) exited with {$exitCode2}: {$error2}");
        }

        return [trim($output1) === '1', trim($output2) === '1'];
    }

    /**
     * Starts one model-method invocation in its own PHP CLI process and lets it go, then gives it
     * time to reach whatever it waits for. Finish it with finishRaceWorker().
     *
     * @return array{process: resource, pipes: array<int, resource>, files: list<string>}
     */
    protected function startRaceWorker(string $method, array $arguments): array
    {
        $ready = tempnam(sys_get_temp_dir(), 'race_ready_');
        $go    = tempnam(sys_get_temp_dir(), 'race_go_');
        unlink($ready);
        unlink($go);

        $environment = array_merge(array_filter($_SERVER, static fn ($value): bool => is_scalar($value)), ['RACE_READY_FILE' => $ready, 'RACE_GO_FILE' => $go]);
        $process     = proc_open([PHP_BINARY, $this->raceWorkerScript(), $method, ...array_map('strval', $arguments)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, $environment);

        if (! is_resource($process)) {
            throw new RuntimeException('The worker did not start.');
        }

        fclose($pipes[0]);

        $deadline = microtime(true) + 10.0;

        while (! file_exists($ready) && microtime(true) < $deadline) {
            $words = $this->raceWorkerEndedEarly($process, $pipes);

            if ($words !== null && ! file_exists($ready)) {
                throw new RuntimeException('The worker did not reach its barrier, ' . $words);
            }

            usleep(1000);
        }

        if (! file_exists($ready)) {
            throw new RuntimeException('The worker did not reach its barrier.');
        }

        file_put_contents($go, '1');
        usleep(800000);

        return ['process' => $process, 'pipes' => $pipes, 'files' => [$ready, $go]];
    }

    /**
     * Several real sessions make a change at the same moment, each in a process of its own over a connection
     * of its own. They all wait at one barrier and are let go together. Each says how it ended: done, or the
     * error of the books that refused it, or the exception that was not the books'.
     *
     * @param list<list<string>> $argumentSets The arguments of each session, without the file it answers in.
     *
     * @return list<string> What each session said, in the order the sessions were given.
     */
    protected function raceSessions(string $method, array $argumentSets): array
    {
        $baseEnv  = array_filter($_SERVER, static fn ($value) => is_scalar($value));
        $goFile   = (string) tempnam(sys_get_temp_dir(), 'race_go_');
        $sessions = [];

        unlink($goFile);

        foreach ($argumentSets as $index => $arguments) {
            $ready   = (string) tempnam(sys_get_temp_dir(), 'race_ready_');
            $outcome = (string) tempnam(sys_get_temp_dir(), 'race_out_');

            unlink($ready);

            $process = proc_open(
                array_merge([PHP_BINARY, $this->raceWorkerScript(), $method], array_map('strval', [...$arguments, $outcome])),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                ROOTPATH,
                array_merge($baseEnv, ['RACE_READY_FILE' => $ready, 'RACE_GO_FILE' => $goFile]),
            );

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $sessions[$index] = ['process' => $process, 'pipes' => $pipes, 'ready' => $ready, 'outcome' => $outcome];
        }

        $answers = [];

        try {
            $deadline = microtime(true) + 30.0;

            while (($waiting = array_filter($sessions, static fn (array $session): bool => ! file_exists($session['ready']))) !== []) {
                foreach ($waiting as $index => $session) {
                    $ended = $this->raceWorkerEndedEarly($session['process'], $session['pipes']);

                    if ($ended !== null && ! file_exists($session['ready'])) {
                        throw new RuntimeException('Session ' . $index . ' of the race did not reach the barrier, ' . $ended);
                    }
                }

                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('A session did not reach the barrier of the race.');
                }

                usleep(1000);
            }

            file_put_contents($goFile, '1');

            $deadline = microtime(true) + 90.0;

            while (array_filter($sessions, static fn (array $session): bool => proc_get_status($session['process'])['running']) !== []) {
                foreach ($sessions as $session) {
                    stream_get_contents($session['pipes'][1]);
                    stream_get_contents($session['pipes'][2]);
                }

                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('A session of the race did not end.');
                }

                usleep(5000);
            }

            foreach ($sessions as $index => $session) {
                $answers[$index] = is_file($session['outcome']) ? trim((string) file_get_contents($session['outcome'])) : '';
            }
        } finally {
            foreach ($sessions as $session) {
                if (proc_get_status($session['process'])['running']) {
                    proc_terminate($session['process']);
                }

                fclose($session['pipes'][1]);
                fclose($session['pipes'][2]);
                proc_close($session['process']);

                foreach ([$session['ready'], $session['outcome']] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }

            if (is_file($goFile)) {
                unlink($goFile);
            }
        }

        return array_values($answers);
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>, files: list<string>} $worker
     */
    protected function raceWorkerIsRunning(array $worker): bool
    {
        return proc_get_status($worker['process'])['running'];
    }

    /**
     * Waits for a worker to end and returns what it printed.
     *
     * @param array{process: resource, pipes: array<int, resource>, files: list<string>} $worker
     */
    protected function finishRaceWorker(array $worker): string
    {
        $output   = '';
        $deadline = microtime(true) + 20.0;

        stream_set_blocking($worker['pipes'][1], false);
        stream_set_blocking($worker['pipes'][2], false);

        do {
            $output .= stream_get_contents($worker['pipes'][1]);
            stream_get_contents($worker['pipes'][2]);
            $status = proc_get_status($worker['process']);
            usleep(5000);
        } while ($status['running'] && microtime(true) < $deadline);

        $output .= stream_get_contents($worker['pipes'][1]);

        if ($status['running']) {
            proc_terminate($worker['process']);
        }

        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);

        foreach ($worker['files'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        return trim($output);
    }
}
