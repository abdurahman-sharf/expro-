<?php

namespace Tests\Config;

use App\Libraries\Accounting\MemoryExhaustedHandler;
use App\Libraries\Roles\ScopeUnavailableHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Exceptions;
use ReflectionProperty;
use RuntimeException;

/**
 * @internal
 */
final class ExceptionsTest extends CIUnitTestCase
{
    public function testHandler_AnswersTheScopeFailuresAndLeavesTheMemoryNoticeToTheHandlerItWraps(): void
    {
        $handler = (new Exceptions())->handler(500, new RuntimeException('Any failure'));

        $this->assertInstanceOf(ScopeUnavailableHandler::class, $handler);
        $this->assertInstanceOf(MemoryExhaustedHandler::class, (new ReflectionProperty(ScopeUnavailableHandler::class, 'next'))->getValue($handler));
    }
}
