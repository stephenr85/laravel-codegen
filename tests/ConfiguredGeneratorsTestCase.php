<?php

namespace Rushing\Codegen\Laravel\Tests;

use Rushing\Codegen\Contracts\Generator;
use Rushing\Popcorn\Binding;

/**
 * A {@see TestCase} that seeds `codegen.generators` *before* the provider boots (codegen-substrate
 * #04). The provider's config-driven discovery reads the key during boot, so a config() call from a
 * test body would be too late — this override runs in Testbench's environment-setup phase, ahead of
 * boot, which is exactly the lifecycle window the feature targets.
 */
class ConfiguredGeneratorsTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('codegen.generators', [
            FixtureConfiguredGenerator::class,
        ]);
    }
}

/**
 * A no-frills native generator listed in config to prove the provider resolves it from the container
 * and registers it. Kept a plain class (constructor-injectable, hence container-resolvable) rather
 * than a ClosureGenerator, since discovery makes each class via app()->make() with no args.
 */
class FixtureConfiguredGenerator implements Generator
{
    public function name(): string
    {
        return 'configured';
    }

    public function stack(): string
    {
        return 'configured';
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invoke(array $input): array
    {
        return ['files' => ['configured.txt' => 'from config']];
    }
}
