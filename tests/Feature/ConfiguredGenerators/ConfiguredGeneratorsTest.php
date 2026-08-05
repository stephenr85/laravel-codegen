<?php

use Rushing\Codegen\Contracts\Generator;
use Rushing\Codegen\GeneratorRegistry;
use Rushing\Codegen\Laravel\Contracts\ModelSource;
use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Primitive;
use Rushing\Codegen\Model\RecordType;
use Rushing\Codegen\Model\Type;
use Rushing\Popcorn\Binding;

/**
 * Config-driven generator discovery (codegen-substrate #04). We can't set `codegen.generators`
 * with `config(...)` inside a test body — the provider reads it during boot, which has already
 * happened. So this suite is bound (in Pest.php) to a dedicated ConfiguredGeneratorsTestCase whose
 * `getEnvironmentSetUp()` seeds the config *before* the provider boots (that's the whole point of
 * the feature: auto-registration at boot from config).
 */
beforeEach(function () {
    $this->outDir = sys_get_temp_dir().'/codegen-configured-test-'.uniqid();
    config(['codegen.out_dir' => $this->outDir]);

    app()->instance(ModelSource::class, new class implements ModelSource
    {
        public function model(): CodegenModel
        {
            return (new CodegenModel)->record('User', fn (RecordType $r) => $r
                ->field('id', Type::primitive(Primitive::Uuid)));
        }
    });
});

afterEach(function () {
    if (is_dir($this->outDir)) {
        array_map('unlink', glob($this->outDir.'/*') ?: []);
        @rmdir($this->outDir);
    }
});

it('auto-registers generator classes listed in config at boot', function () {
    expect(app(GeneratorRegistry::class)->has('configured'))->toBeTrue();
});

it('runs a config-registered generator via codegen:generate', function () {
    $this->artisan('codegen:generate', ['--stack' => ['configured']])
        ->expectsOutputToContain('configured: 1 file(s)')
        ->assertSuccessful();

    expect(file_get_contents($this->outDir.'/configured.txt'))->toBe('from config');
});

it('composes config discovery with manual registration', function () {
    app(GeneratorRegistry::class)->register(new class implements Generator
    {
        public function name(): string
        {
            return 'manual';
        }

        public function stack(): string
        {
            return 'manual';
        }

        public function binding(): Binding
        {
            return Binding::Local;
        }

        public function invoke(array $input): array
        {
            return ['files' => ['manual.txt' => 'manual']];
        }
    });

    $registry = app(GeneratorRegistry::class);

    expect($registry->has('configured'))->toBeTrue()
        ->and($registry->has('manual'))->toBeTrue();
});
