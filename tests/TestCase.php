<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Forget the resolved auth guards before each test request.
     *
     * The Sanctum RequestGuard caches a successfully resolved user for the
     * whole test process, so a later request in the same test would keep
     * seeing an earlier identity (or a revoked token). Resetting the guards
     * makes every request authenticate from its own bearer token.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->make('auth')->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
