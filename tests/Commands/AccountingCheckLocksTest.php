<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;

class AccountingCheckLocksTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    public function testRun_SaysTheConnectionKeepsNamedLocks(): void
    {
        $this->resetStreamFilterBuffer();

        command('accounting:check_locks');

        $this->assertStringContainsString('keeps named locks', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('does not keep', $this->getStreamFilterBuffer());
    }
}
