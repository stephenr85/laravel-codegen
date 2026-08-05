<?php

namespace Rushing\Codegen\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Rushing\Codegen\Contracts\Generator;
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

        $this->registerConfiguredGenerators();
    }

    /**
     * Config-driven generator discovery (codegen-substrate #04): a host lists generator classes under
     * `codegen.generators` and we auto-register each into the {@see GeneratorRegistry} at boot — no
     * manual container wiring required. Each class is resolved *from the container*
     * ({@see Application::make()}) so a generator may declare
     * constructor dependencies, and so it participates in the same lifecycle as any other binding.
     *
     * This composes with manual registration: the registry is a singleton, so a host that also calls
     * `app(GeneratorRegistry::class)->register(...)` simply adds to the same registry — order is the
     * only difference, and last-write-wins per stack is popcorn's own semantics.
     *
     * Defensive-by-design (skip-silently, not throw): a bad entry — a class that doesn't exist or
     * isn't a {@see Generator} — is quietly skipped rather than fataling the whole application boot.
     * A codegen misconfig is a dev-time authoring mistake surfaced the moment they run
     * `codegen:generate` (the stack simply won't be there); it should never take down an unrelated
     * request/queue worker that merely has the provider installed. If you want a hard signal, prefer
     * a dedicated `codegen:doctor`-style check over throwing here.
     */
    protected function registerConfiguredGenerators(): void
    {
        $registry = $this->app->make(GeneratorRegistry::class);

        foreach ((array) config('codegen.generators', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $generator = $this->app->make($class);

            if (! $generator instanceof Generator) {
                continue;
            }

            $registry->register($generator);
        }
    }
}
