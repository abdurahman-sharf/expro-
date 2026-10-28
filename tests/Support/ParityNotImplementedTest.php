<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The features the reference system has and this application does not have yet. Each is a case of a
 * fixture that says what is absent, and this test asserts that it still is: the day a package builds
 * the feature the case fails, and the fixture is brought up to date on purpose.
 */
class ParityNotImplementedTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function gapProvider(): array
    {
        $sets = [];

        foreach (ParityFixture::ids() as $id) {
            foreach (ParityFixture::load($id)['cases'] as $case) {
                if (($case['classification'] ?? null) === 'not_implemented') {
                    $sets["{$id} {$case['id']}"] = [$id, $case];
                }
            }
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('gapProvider')]
    public function testAbsences_ConfirmThatWhatTheReferenceSystemHasIsStillMissing(string $fixture, array $case): void
    {
        $this->assertSame('not_implemented', $case['expected']['status']);

        foreach ($case['expected']['absent'] as $absence) {
            $this->assertSame([], $this->found($absence), "{$fixture} {$case['id']} is recorded as not implemented ({$case['work_package']}) but " . json_encode($absence) . ' now exists');
        }
    }

    /**
     * @param array<string, string> $absence
     *
     * @return list<string> What exists of what the case says is absent.
     */
    private function found(array $absence): array
    {
        return match ($absence['kind']) {
            'table'  => $this->tables($absence['name'] ?? null, $absence['pattern'] ?? null),
            'column' => $this->columns($absence['table'], $absence['name'] ?? null, $absence['pattern'] ?? null),
            'class'  => class_exists($absence['name']) ? [$absence['name']] : [],
            'route'  => $this->routes($absence['pattern']),
        };
    }

    /**
     * @return list<string>
     */
    private function tables(?string $name, ?string $pattern): array
    {
        $db     = Database::connect();
        $prefix = $db->getPrefix();

        return array_values(array_filter(
            array_map(static fn (string $table): string => substr($table, strlen($prefix)), $db->listTables()),
            static fn (string $table): bool => $table === $name || ($pattern !== null && stripos($table, $pattern) !== false),
        ));
    }

    /**
     * @return list<string>
     */
    private function columns(string $table, ?string $name, ?string $pattern): array
    {
        return array_values(array_filter(
            Database::connect()->getFieldNames($table),
            static fn (string $column): bool => $column === $name || ($pattern !== null && stripos($column, $pattern) !== false),
        ));
    }

    /**
     * What the application would answer to a path with the pattern in it: an explicit route, a
     * controller or a public method of a controller.
     *
     * @return list<string>
     */
    private function routes(string $pattern): array
    {
        $found = [];

        if (stripos((string) file_get_contents(APPPATH . 'Config/Routes.php'), $pattern) !== false) {
            $found[] = 'app/Config/Routes.php';
        }

        foreach (glob(APPPATH . 'Controllers/*.php') ?: [] as $file) {
            $class = 'App\\Controllers\\' . basename($file, '.php');

            if (stripos($class, $pattern) !== false) {
                $found[] = $class;
            }

            foreach (get_class_methods($class) as $method) {
                if (stripos($method, $pattern) !== false) {
                    $found[] = "{$class}::{$method}";
                }
            }
        }

        return $found;
    }
}
