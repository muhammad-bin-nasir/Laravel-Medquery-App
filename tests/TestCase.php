<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests must never touch a real database. A cached config file silently overrides the
     * connection values in phpunit.xml, which previously let RefreshDatabase run
     * migrate:fresh against the development database. Pinning the connection here happens
     * before test traits boot, so migrations always target a throwaway in-memory database.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        config([
            'database.default' => 'testing_memory',
            'database.connections.testing_memory' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'testing_memory' || $database !== ':memory:') {
            throw new RuntimeException(
                'Refusing to run tests against a persistent database. Run "php artisan config:clear" first.'
            );
        }
    }
}
