<?php

namespace Rushing\Codegen\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Codegen\Laravel\CodegenServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            // laravel-popcorn binds RegistryIndex as a SINGLETON. Without it the index is
            // auto-resolvable but unshared, so describe() lands on a throwaway and silently does
            // nothing — index membership as a function of host composition (ticket 04 D1).
            PopcornServiceProvider::class,
            CodegenServiceProvider::class,
        ];
    }
}
