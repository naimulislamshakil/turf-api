<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function beforeRefreshingDatabase()
    {
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'traf_management_test') {
            throw new \RuntimeException('Tests must use PostgreSQL database traf_management_test.');
        }

        if (config('cache.default') !== 'database' || config('queue.default') !== 'database') {
            throw new \RuntimeException('Tests must use database cache and database queue.');
        }
    }
}
