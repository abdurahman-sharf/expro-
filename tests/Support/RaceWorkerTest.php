<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The process that races a method of the application against another. It must run the application of
 * the checkout it sits in, whatever the vendor directory of that checkout is, and refuse to run at all
 * when it cannot.
 *
 * @internal
 */
final class RaceWorkerTest extends CIUnitTestCase
{
    use ConcurrentDbRaceTrait;

    private ?string $checkout     = null;
    private ?string $workerScript = null;

    protected function raceWorkerScript(): string
    {
        return $this->workerScript ?? __DIR__ . '/RaceWorker.php';
    }

    protected function tearDown(): void
    {
        if ($this->checkout !== null && is_dir($this->checkout)) {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->checkout, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($items as $item) {
                $item->isLink() || $item->isFile() ? unlink($item->getPathname()) : rmdir($item->getPathname());
            }

            rmdir($this->checkout);
        }

        $this->checkout     = null;
        $this->workerScript = null;

        parent::tearDown();
    }

    /**
     * A copy of the layout of a checkout whose vendor directory is a link to this one, as a git worktree
     * made with a linked vendor directory is. Everything of the application is linked to this checkout
     * except the sale model, which is a copy, so that the file a class was loaded from says which checkout
     * it came from.
     */
    private function checkoutWithLinkedVendor(bool $ownPaths = true): string
    {
        $root = rtrim(ROOTPATH, '/');
        $base = (string) realpath(sys_get_temp_dir());
        $dir  = $base . '/race_checkout_' . uniqid();

        mkdir($dir . '/tests/Support', 0o777, true);
        copy($root . '/tests/Support/RaceWorker.php', $dir . '/tests/Support/RaceWorker.php');

        foreach (['vendor', 'public', 'writable'] as $linked) {
            symlink($root . '/' . $linked, $dir . '/' . $linked);
        }

        mkdir($dir . '/app');

        foreach (array_diff(scandir($root . '/app'), ['.', '..', 'Config', 'Models']) as $entry) {
            symlink($root . '/app/' . $entry, $dir . '/app/' . $entry);
        }

        foreach (['Config' => 'Paths.php', 'Models' => 'Sale.php'] as $folder => $copied) {
            mkdir($dir . '/app/' . $folder);

            foreach (array_diff(scandir($root . '/app/' . $folder), ['.', '..']) as $entry) {
                $from = $root . '/app/' . $folder . '/' . $entry;
                $to   = $dir . '/app/' . $folder . '/' . $entry;

                $entry === $copied && ($ownPaths || $folder !== 'Config') ? copy($from, $to) : symlink($from, $to);
            }
        }

        return $this->checkout = $dir;
    }

    /**
     * @return array{output: string, error: string, exit: int}
     */
    private function runWorker(string $checkout, string $method): array
    {
        $go = (string) tempnam(sys_get_temp_dir(), 'race_go_');

        file_put_contents($go, '1');

        $process = proc_open(
            [PHP_BINARY, $checkout . '/tests/Support/RaceWorker.php', $method],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $checkout,
            array_merge(array_filter($_SERVER, static fn ($value): bool => is_scalar($value)), ['RACE_READY_FILE' => $go . '.ready', 'RACE_GO_FILE' => $go]),
        );

        fclose($pipes[0]);

        $output = (string) stream_get_contents($pipes[1]);
        $error  = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        unlink($go);
        is_file($go . '.ready') && unlink($go . '.ready');

        return ['output' => trim($output), 'error' => trim($error), 'exit' => $exit];
    }

    public function testLoadedFrom_ReportsTheApplicationOfTheCheckoutTheWorkerSitsIn(): void
    {
        $answer = $this->runWorker(rtrim(ROOTPATH, '/'), 'loadedFrom');

        $this->assertSame(0, $answer['exit'], $answer['error']);
        $this->assertSame((string) realpath(ROOTPATH . 'app/Models/Sale.php'), $answer['output']);
    }

    public function testLoadedFrom_ReportsTheApplicationOfTheCheckoutWhoseVendorDirectoryIsALink(): void
    {
        $checkout = (string) realpath($this->checkoutWithLinkedVendor());
        $answer   = $this->runWorker($checkout, 'loadedFrom');

        $this->assertSame(0, $answer['exit'], $answer['error']);
        $this->assertSame($checkout . '/app/Models/Sale.php', $answer['output'], 'The worker loaded the application of the checkout the vendor directory is linked to');
    }

    public function testLoadedFrom_RefusesToRunWhenTheApplicationWouldBeLoadedFromAnotherCheckout(): void
    {
        $checkout = (string) realpath($this->checkoutWithLinkedVendor(false));
        $answer   = $this->runWorker($checkout, 'loadedFrom');

        $this->assertSame(3, $answer['exit']);
        $this->assertSame('', $answer['output']);
        $this->assertStringContainsString('would load the application from outside ' . $checkout, $answer['error']);
    }

    public function testRaceSessions_RefusesAtOnceWithTheWordsOfAWorkerThatEndedBeforeTheBarrier(): void
    {
        $checkout           = (string) realpath($this->checkoutWithLinkedVendor(false));
        $this->workerScript = $checkout . '/tests/Support/RaceWorker.php';
        $started            = microtime(true);

        try {
            $this->raceSessions('loadedFrom', [[]]);

            $this->fail('The race was not refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('did not reach the barrier', $exception->getMessage());
            $this->assertStringContainsString('exit code 3', $exception->getMessage());
            $this->assertStringContainsString('would load the application from outside ' . $checkout, $exception->getMessage());
        }

        $this->assertLessThan(15.0, microtime(true) - $started, 'The refusal came when the worker ended and not when the wait for the barrier ran out');
    }

    public function testRaceTwoProcesses_RefusesAtOnceWithTheWordsOfTheWorkersThatEndedBeforeTheBarrier(): void
    {
        $checkout           = (string) realpath($this->checkoutWithLinkedVendor(false));
        $this->workerScript = $checkout . '/tests/Support/RaceWorker.php';
        $started            = microtime(true);

        try {
            $this->raceTwoProcesses('loadedFrom', [], []);

            $this->fail('The race was not refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed to reach readiness barrier', $exception->getMessage());
            $this->assertStringContainsString('exit code 3', $exception->getMessage());
            $this->assertStringContainsString('would load the application from outside ' . $checkout, $exception->getMessage());
        }

        $this->assertLessThan(4.0, microtime(true) - $started, 'The refusal came when the workers ended and not when the wait for the barrier ran out');
    }

    public function testStartRaceWorker_RefusesAtOnceWithTheWordsOfAWorkerThatEndedBeforeItsBarrier(): void
    {
        $checkout           = (string) realpath($this->checkoutWithLinkedVendor(false));
        $this->workerScript = $checkout . '/tests/Support/RaceWorker.php';
        $started            = microtime(true);

        try {
            $this->startRaceWorker('loadedFrom', []);

            $this->fail('The worker was not refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('did not reach its barrier', $exception->getMessage());
            $this->assertStringContainsString('would load the application from outside ' . $checkout, $exception->getMessage());
        }

        $this->assertLessThan(8.0, microtime(true) - $started, 'The refusal came when the worker ended and not when the wait for the barrier ran out');
    }
}
