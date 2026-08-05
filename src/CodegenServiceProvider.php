<?php

namespace Rushing\Codegen\Laravel;

use Illuminate\Support\ServiceProvider;
use Rushing\Codegen\GeneratorRegistry;
use Rushing\Codegen\Laravel\Console\GenerateCommand;
use Rushing\Codegen\Laravel\Contracts\ModelSource;
use Rushing\Popcorn\InvocableRegistry;

/**
 * Wires rushing/codegen into Laravel (codegen-substrate #04): binds the registry singletons, publishes
 * config, and registers the `codegen:generate` command. Deliberately does NOT bind a
 * {@see ModelSource} or register any generators — those are the
 * host's job (the app registers its `ts-client`/`php-saloon` generators and its model source).
 *
 * Binds its own `InvocableRegistry` singleton so it stands alone; if `rushing/laravel-popcorn` is also
 * installed it simply shares the same class binding (last registration wins, same no-arg singleton).
 */
class CodegenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/codegen.php', 'codegen');

        $this->app->singleton(InvocableRegistry::class);
        $this->app->singleton(
            GeneratorRegistry::class,
            fn ($app) => new GeneratorRegistry($app->make(InvocableRegistry::class)),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/codegen.php' => config_path('codegen.php'),
            ], 'codegen-config');

            $this->commands([GenerateCommand::class]);
        }
    }
}
