<?php

namespace Tests\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

class DatabaseTest extends CIUnitTestCase
{
    private string|false $portBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->portBefore = getenv('MYSQL_PORT');
    }

    protected function tearDown(): void
    {
        $this->portBefore === false ? putenv('MYSQL_PORT') : putenv('MYSQL_PORT=' . $this->portBefore);

        parent::tearDown();
    }

    public function testConstruct_TakesThePortOfEveryGroupFromTheEnvironment(): void
    {
        putenv('MYSQL_PORT=3307');

        $config = new Database();

        $this->assertSame(3307, $config->tests['port']);
        $this->assertSame(3307, $config->development['port']);
        $this->assertSame(3307, $config->default['port']);
    }

    public function testConstruct_KeepsThePortOfTheConfigurationWhenTheEnvironmentHasNone(): void
    {
        putenv('MYSQL_PORT');

        $config = new Database();

        $this->assertNotSame(3307, $config->tests['port']);
    }
}
