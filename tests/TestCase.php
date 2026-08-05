<?php

namespace Rushing\Codegen\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Codegen\Laravel\CodegenServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CodegenServiceProvider::class];
    }
}
