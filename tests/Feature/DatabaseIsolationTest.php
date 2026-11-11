<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_configuration_is_rejected_before_database_work(
        string $connection,
        string $database,
        string $username,
        string $host,
        ?string $url,
    ): void
    {
        config([
            'database.default' => $connection,
            "database.connections.{$connection}" => compact('database', 'username', 'host', 'url'),
        ]);
        $this->expectException(RuntimeException::class);
        $this->ensureDatabaseIsIsolated($this->app);
    }

    public static function unsafeConfigurations(): array
    {
        return [
            ['mysql', 'easycare_dev', 'easycare_test', '127.0.0.1', null],
            ['mysql', 'easycare_test', 'easycare_dev', '127.0.0.1', null],
            ['mysql', 'easycare_test', 'easycare_test', 'hospital.example', null],
            ['mysql', 'easycare_test', 'easycare_test', '127.0.0.1', 'mysql://example.invalid'],
            ['sqlite', 'database.sqlite', '', '', null],
            ['sqlite', ':memory:', '', '', 'sqlite://example.invalid'],
        ];
    }

    public function test_a_non_testing_environment_is_rejected(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $this->ensureDatabaseIsIsolated($this->app);
    }
}
