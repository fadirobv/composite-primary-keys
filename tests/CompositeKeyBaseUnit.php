<?php

namespace MaksimM\CompositePrimaryKeys\Tests;

use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use MaksimM\CompositePrimaryKeys\Seeders\TestBinaryRoleSeeder;
use MaksimM\CompositePrimaryKeys\Seeders\TestBinaryUserSeeder;
use MaksimM\CompositePrimaryKeys\Seeders\TestOrganizationSeeder;
use MaksimM\CompositePrimaryKeys\Seeders\TestRoleSeeder;
use MaksimM\CompositePrimaryKeys\Seeders\TestUserNonCompositeSeeder;
use MaksimM\CompositePrimaryKeys\Seeders\TestUserSeeder;
use Orchestra\Testbench\TestCase;

class CompositeKeyBaseUnit extends TestCase
{
    /**
     * Setup the test environment.
     *
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->artisan('migrate', ['--database' => 'testing']);

        $this->seed(TestOrganizationSeeder::class);
        $this->seed(TestRoleSeeder::class);
        $this->seed(TestBinaryRoleSeeder::class);
        $this->seed(TestUserSeeder::class);
        $this->seed(TestUserNonCompositeSeeder::class);
        $this->seed(TestBinaryUserSeeder::class);

        if (env('DEBUG_QUERY_LOG', true)) {
            DB::listen(
                function (QueryExecuted $queryExecuted) {
                    var_dump($queryExecuted->sql);
                    var_dump($queryExecuted->bindings);
                }
            );
        }
    }

    /**
     * Define environment setup.
     *
     * @param Application $app
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function loadMigrationsFrom($paths): void
    {
        $paths = (is_array($paths)) ? $paths : [$paths];
        $this->app->afterResolving('migrator', function ($migrator) use ($paths) {
            foreach ((array) $paths as $path) {
                $migrator->path($path);
            }
        });
    }
}
