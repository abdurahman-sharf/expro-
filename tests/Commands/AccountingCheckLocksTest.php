<?php

namespace Tests\Commands;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use ReflectionProperty;
use Tests\Support\PooledConnection;

class AccountingCheckLocksTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private array $sharedConnections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sharedConnections = (new ReflectionProperty(Config::class, 'instances'))->getValue();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Config::class, 'instances'))->setValue(null, $this->sharedConnections);

        parent::tearDown();
    }

    public function testRun_SaysTheConnectionKeepsNamedLocks(): void
    {
        $this->resetStreamFilterBuffer();

        command('accounting:check_locks');

        $this->assertStringContainsString('keeps named locks', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('does not keep', $this->getStreamFilterBuffer());
    }

    public function testRun_SaysTheConnectionDoesNotKeepNamedLocksBehindAPooler(): void
    {
        $pooled = new PooledConnection(config('Database')->tests);
        $pooled->sendStatementsMentioning('IS_USED_LOCK', Config::connect('tests', false));
        (new ReflectionProperty(Config::class, 'instances'))->setValue(null, ['tests' => $pooled] + $this->sharedConnections);
        $this->resetStreamFilterBuffer();

        command('accounting:check_locks');

        $this->assertStringContainsString('does not keep named locks (not_held)', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('keeps named locks.', $this->getStreamFilterBuffer());
    }
}
