<?php

namespace Tests\Database;

use App\Database\Seeds\TestDatabaseBootstrapSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

class TestDatabaseBootstrapSeederTest extends CIUnitTestCase
{
    public function testServerConnectionConfig_CarriesThePortOfTheGroup(): void
    {
        $group = [...(new Database())->tests, 'port' => 3307];

        $config = TestDatabaseBootstrapSeeder::serverConnectionConfig($group);

        $this->assertSame(3307, $config['port']);
        $this->assertNull($config['database']);
    }

    public function testServerConnectionConfig_FallsBackToTheDefaultPortWhenTheGroupHasNone(): void
    {
        $group = (new Database())->tests;
        unset($group['port']);

        $config = TestDatabaseBootstrapSeeder::serverConnectionConfig($group);

        $this->assertSame(3306, $config['port']);
    }

    public function testServerConnectionConfig_KeepsTheHostAndTheCredentialsOfTheGroup(): void
    {
        $group = (new Database())->tests;

        $config = TestDatabaseBootstrapSeeder::serverConnectionConfig($group);

        $this->assertSame([$group['hostname'], $group['username'], $group['password'], $group['DBDriver']], [$config['hostname'], $config['username'], $config['password'], $config['DBDriver']]);
    }
}
