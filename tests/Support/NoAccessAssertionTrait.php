<?php

namespace Tests\Support;

use CodeIgniter\Test\TestResponse;

/**
 * Proves that a screen refused a request with the page that says access is refused, the way a screen answers someone
 * who lacks the permission of its own.
 */
trait NoAccessAssertionTrait
{
    protected function assertNoAccess(TestResponse $response, string $moduleId, string $permissionId): void
    {
        $response->assertRedirect();
        $this->assertStringEndsWith("no_access/{$moduleId}/{$permissionId}", (string) $response->getRedirectUrl());
    }
}
