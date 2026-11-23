<?php

namespace Tests\Config;

use App\Libraries\Accounting\MemoryExhaustedHandler;
use App\Libraries\Database\OpenTransactionHandler;
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
    public function testHandler_EndsTheOpenTransactionThenAnswersTheScopeFailuresAndLeavesTheMemoryNoticeToTheHandlerItWraps(): void
    {
        $handler = (new Exceptions())->handler(500, new RuntimeException('Any failure'));
        $scope   = (new ReflectionProperty(OpenTransactionHandler::class, 'next'))->getValue($handler);

        $this->assertInstanceOf(OpenTransactionHandler::class, $handler);
        $this->assertInstanceOf(ScopeUnavailableHandler::class, $scope);
        $this->assertInstanceOf(MemoryExhaustedHandler::class, (new ReflectionProperty(ScopeUnavailableHandler::class, 'next'))->getValue($scope));
    }
}
