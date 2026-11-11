<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $this->ensureDatabaseIsIsolated($app);

        return $app;
    }

    protected function ensureDatabaseIsIsolated(Application $app): void
    {
        $connection = $app['config']->get('database.default');
        $settings = $app['config']->get("database.connections.{$connection}");

        $safeSqlite = $connection === 'sqlite' && $settings['database'] === ':memory:' && empty($settings['url']);
        $safeMysql = $connection === 'mysql'
            && $settings['database'] === 'easycare_test'
            && $settings['username'] === 'easycare_test'
            && in_array($settings['host'], ['127.0.0.1', 'localhost', '::1'], true)
            && empty($settings['url']);

        if (! $app->environment('testing') || $app->configurationIsCached() || (! $safeSqlite && ! $safeMysql)) {
            throw new RuntimeException('Tests require uncached testing configuration and an isolated test database.');
        }
    }
}
