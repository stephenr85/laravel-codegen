<?php

use Rushing\Codegen\GeneratorRegistry;
use Rushing\Codegen\Generators\ClosureGenerator;
use Rushing\Codegen\Laravel\Contracts\ModelSource;
use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Primitive;
use Rushing\Codegen\Model\RecordType;
use Rushing\Codegen\Model\Type;

beforeEach(function () {
    $this->outDir = sys_get_temp_dir().'/codegen-test-'.uniqid();
    $this->stackOutDir = sys_get_temp_dir().'/codegen-test-stack-'.uniqid();
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
    foreach ([$this->outDir, $this->stackOutDir] as $dir) {
        if (is_dir($dir)) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }
});

it('resolves generators from the registry, invokes them, and writes files', function () {
    app(GeneratorRegistry::class)->register(new ClosureGenerator('fake', function (array $model) {
        $name = $model['records'][0]['name'];

        return ["{$name}.txt" => "record {$name}"];
    }));

    $this->artisan('codegen:generate')
        ->expectsOutputToContain('fake: 1 file(s)')
        ->assertSuccessful();

    expect(file_get_contents($this->outDir.'/User.txt'))->toBe('record User');
});

it('honours the --stack filter', function () {
    $registry = app(GeneratorRegistry::class);
    $registry->register(new ClosureGenerator('a', fn (array $m) => ['a.txt' => 'a']));
    $registry->register(new ClosureGenerator('b', fn (array $m) => ['b.txt' => 'b']));

    $this->artisan('codegen:generate', ['--stack' => ['a']])->assertSuccessful();

    expect(file_exists($this->outDir.'/a.txt'))->toBeTrue()
        ->and(file_exists($this->outDir.'/b.txt'))->toBeFalse();
});

it('writes each stack to its own out_dir, falling back to the global out_dir', function () {
    // Stack `a` pins its own out_dir; stack `b` inherits the global out_dir.
    config(['codegen.options.a.out_dir' => $this->stackOutDir]);

    $registry = app(GeneratorRegistry::class);
    $registry->register(new ClosureGenerator('a', fn (array $m) => ['a.txt' => 'a']));
    $registry->register(new ClosureGenerator('b', fn (array $m) => ['b.txt' => 'b']));

    $this->artisan('codegen:generate', ['--stack' => ['a', 'b']])->assertSuccessful();

    // `a` landed under its per-stack dir, not the global one.
    expect(file_get_contents($this->stackOutDir.'/a.txt'))->toBe('a')
        ->and(file_exists($this->outDir.'/a.txt'))->toBeFalse()
        // `b` landed under the global dir.
        ->and(file_get_contents($this->outDir.'/b.txt'))->toBe('b')
        ->and(file_exists($this->stackOutDir.'/b.txt'))->toBeFalse();
});

it('rejects an invalid model before generating', function () {
    app()->instance(ModelSource::class, new class implements ModelSource
    {
        public function model(): CodegenModel
        {
            return (new CodegenModel)->record('Broken', fn (RecordType $r) => $r
                ->field('x', Type::ref('Missing')));   // dangling ref
        }
    });

    app(GeneratorRegistry::class)->register(new ClosureGenerator('fake', fn (array $m) => ['x.txt' => 'x']));

    // The validation failure is REPORTED, not propagated: it is one stack's failure, and the command
    // now owns the decision about what happens to the other stacks. Exit code still says failed.
    $this->artisan('codegen:generate')->assertFailed();

    expect(file_exists($this->outDir.'/x.txt'))->toBeFalse();
});

it('does not let one stack failure abort the stacks that follow it', function () {
    // The measured shape of api-surface-coherence 57: the FIRST stack fatals (there, a schema whose
    // title was not a legal class name) and every stack after it silently never runs. A truncated
    // regeneration reads exactly like a complete one, which makes the estate's drift detector report
    // success by not running.
    $registry = app(GeneratorRegistry::class);
    $registry->register(new ClosureGenerator('boom', function (array $m) {
        throw new RuntimeException('this stack is broken');
    }));
    $registry->register(new ClosureGenerator('after', fn (array $m) => ['after.txt' => 'after']));

    $this->artisan('codegen:generate', ['--stack' => ['boom', 'after']])
        ->expectsOutputToContain('this stack is broken')
        ->assertFailed();

    // The stack AFTER the failure still ran and still wrote.
    expect(file_get_contents($this->outDir.'/after.txt'))->toBe('after');
});

it('reports every failing stack, not just the first', function () {
    $registry = app(GeneratorRegistry::class);
    $registry->register(new ClosureGenerator('boom-a', fn (array $m) => throw new RuntimeException('first failure')));
    $registry->register(new ClosureGenerator('boom-b', fn (array $m) => throw new RuntimeException('second failure')));

    $this->artisan('codegen:generate', ['--stack' => ['boom-a', 'boom-b']])
        ->expectsOutputToContain('first failure')
        ->expectsOutputToContain('second failure')
        ->expectsOutputToContain('2 of 2 stack(s) failed')
        ->assertFailed();
});
