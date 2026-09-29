<?php

namespace Cordon\Tests;

use Cordon\Laravel\CordonServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CordonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cordon.resolver', 'namespace');
        $app['config']->set('cordon.resolvers.namespace.path', __DIR__.'/Fixtures/namespace-app/app/Modules');
        $app['config']->set('cordon.resolvers.namespace.namespace', 'App\\Modules');
        $app['config']->set('cordon.modules', ['Shared' => ['open' => true]]);
        $app['config']->set('cordon.baseline', sys_get_temp_dir().'/cordon-feature-baseline-'.getmypid().'.json');
    }
}
