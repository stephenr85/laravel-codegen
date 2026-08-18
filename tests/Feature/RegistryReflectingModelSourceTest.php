<?php

use App\Data\Format;
use Rushing\Codegen\GeneratorRegistry;
use Rushing\Codegen\Laravel\Contracts\ModelSource;
use Rushing\Codegen\Laravel\Generators\DataClassGenerator;
use Rushing\Codegen\Model\CodegenModel;

/**
 * Proves a `ModelSource` can wrap a live, registry-shaped object (the same `register()`/lookup
 * shape as `splicewire/laravel-beam-ux`'s `CodecRegistry`) and generate a real PHP backed enum
 * reflecting its CURRENT state — not a fixed literal list, the shape every other `ModelSource`
 * example uses today. See the ticket for the full design/decisions:
 * `.scratch/rushing/laravel-codegen/registry-backed-enums/01-registry-reflecting-model-source.md`
 * in the splicewire-ecosystem repo.
 *
 * No new package code — `ModelSource` + `CodegenModel::enum()` + `DataClassGenerator` already
 * compose into this; what was missing was a worked example proving it, specifically that
 * regenerating after the registry's own state changes actually changes the generated output.
 */
class FakeRegistry
{
    /** @var array<int, string> */
    private array $names = [];

    public function register(string $name): static
    {
        $this->names[] = $name;

        return $this;
    }

    /** @return array<int, string> */
    public function formats(): array
    {
        return $this->names;
    }
}

beforeEach(function () {
    $this->outDir = sys_get_temp_dir().'/codegen-registry-test-'.uniqid();
    config(['codegen.out_dir' => $this->outDir]);

    $this->registry = new FakeRegistry;
    $this->registry->register('tsx')->register('mdx');

    app()->instance(ModelSource::class, new class($this) implements ModelSource
    {
        public function __construct(private object $test) {}

        public function model(): CodegenModel
        {
            return (new CodegenModel)->enum('Format', $this->test->registry->formats());
        }
    });

    app(GeneratorRegistry::class)->register(new DataClassGenerator);
});

afterEach(function () {
    if (is_dir($this->outDir)) {
        array_map('unlink', glob($this->outDir.'/*') ?: []);
        @rmdir($this->outDir);
    }
});

it('generates a real backed enum reflecting the registry\'s current entries', function () {
    $this->artisan('codegen:generate', ['--stack' => ['php-data']])->assertSuccessful();

    $path = $this->outDir.'/Format.php';
    expect(file_exists($path))->toBeTrue();

    require_once $path;

    expect(enum_exists('App\Data\Format'))->toBeTrue();

    $cases = array_map(fn ($c) => $c->name, Format::cases());
    expect($cases)->toEqualCanonicalizing(['Tsx', 'Mdx']);

    expect(Format::from('tsx')->name)->toBe('Tsx');
});

it('reflects a NEWLY registered entry on the next generate — the actual value proposition', function () {
    $this->artisan('codegen:generate', ['--stack' => ['php-data']])->assertSuccessful();
    $firstRun = file_get_contents($this->outDir.'/Format.php');
    expect($firstRun)->not->toContain("case Css = 'css';");

    // The registry gains an entry — nothing about the ModelSource/generator changed, only the
    // live object's state. A snapshot-based approach couldn't see this; a registry-reflecting one
    // must.
    $this->registry->register('css');

    $this->artisan('codegen:generate', ['--stack' => ['php-data']])->assertSuccessful();
    $secondRun = file_get_contents($this->outDir.'/Format.php');

    expect($secondRun)->toContain("case Css = 'css';")
        ->and($secondRun)->not->toBe($firstRun);
});
