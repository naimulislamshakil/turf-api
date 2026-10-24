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

        if ((string) config('database.redis.default.database') !== '2' || (string) config('database.redis.cache.database') !== '3') {
            throw new \RuntimeException('Tests must use Redis queue database 2 and cache database 3.');
        }
    }
}
