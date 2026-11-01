<?php

use Rushing\Codegen\Hydration\JsonSchemaHydrator;
use Rushing\Codegen\Laravel\Generators\DataClassGenerator;
use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Primitive;
use Rushing\Codegen\Model\RecordType;
use Rushing\Codegen\Model\Type;
use Spatie\LaravelData\LaravelDataServiceProvider;

function phpLint(string $code): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'datagen').'.php';
    file_put_contents($tmp, $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1');
    @unlink($tmp);

    return $out;
}

it('emits a spatie Data class with typed promoted properties and a backed enum', function () {
    $model = (new CodegenModel)
        ->enum('Status', ['draft', 'published'])
        ->record('Post', fn (RecordType $r) => $r
            ->field('id', Type::primitive(Primitive::Uuid))
            ->field('status', Type::ref('Status'))
            ->field('author', Type::external('App.Models.User'))
            ->field('tags', Type::listOf(Type::primitive(Primitive::String)))
            ->field('score', Type::optional(Type::primitive(Primitive::Int))));

    $files = (new DataClassGenerator)->invoke(['model' => $model->toArray(), 'options' => []])['files'];

    expect($files)->toHaveKeys(['Status.php', 'Post.php']);

    $post = $files['Post.php'];
    expect($post)
        ->toContain('namespace App\Data;')
        ->toContain('use Spatie\LaravelData\Data;')
        ->toContain('class Post extends Data')
        ->toContain('public string $id')
        ->toContain('public Status $status')                 // internal ref → sibling class
        ->toContain('public \App\Models\User $author')        // external ref (other ns) → FQCN
        ->toContain('public array $tags')
        ->toContain('public ?int $score = null');            // optional → nullable + default

    $status = $files['Status.php'];
    expect($status)
        ->toContain('enum Status: string')
        ->toContain("case Draft = 'draft';")
        ->toContain("case Published = 'published';");
});

it('closes the round-trip: JSON Schema -> model -> Data class', function () {
    $schema = [
        '$defs' => [
            'Widget' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'label' => ['type' => 'string'],          // optional
                    'size' => ['type' => 'integer'],           // optional
                ],
            ],
        ],
    ];

    $model = (new JsonSchemaHydrator)->hydrate($schema)->validate();
    $files = (new DataClassGenerator)->invoke(['model' => $model->toArray(), 'options' => []])['files'];

    expect($files)->toHaveKey('Widget.php');
    expect($files['Widget.php'])
        ->toContain('class Widget extends Data')
        ->toContain('public string $id')                      // required
        ->toContain('public ?string $label = null')           // not required → nullable
        ->toContain('public ?int $size = null');
});

it('emits array-element docblocks that resolve from the generated namespace', function () {
    $model = (new CodegenModel)
        ->record('Location', fn (RecordType $r) => $r->field('city', Type::primitive(Primitive::String)))
        ->record('Dealer', fn (RecordType $r) => $r
            ->field('locations', Type::listOf(Type::ref('Location')))
            ->field('owners', Type::listOf(Type::external('App.Models.User')))
            ->field('byRegion', Type::mapOf(Type::ref('Location')))
            ->field('provenance', Type::listOf(Type::primitive(Primitive::Json))));

    $files = (new DataClassGenerator)->invoke([
        'model' => $model->toArray(),
        'options' => ['namespace' => 'App\\Generated\\Data'],
    ])['files'];

    $dealer = $files['Dealer.php'];

    expect($dealer)
        ->toContain('namespace App\Generated\Data;')
        // A sibling in the file's own namespace reads short — an unrooted `App\Generated\Data\Location`
        // would resolve to `App\Generated\Data\App\Generated\Data\Location`.
        ->toContain('@param Location[] $locations')
        ->toContain('@param array<string, Location> $byRegion')
        // A class from another namespace keeps its root.
        ->toContain('@param \App\Models\User[] $owners')
        // A `json` element is `array` in the signature but `array<string, mixed>` in the doc: a bare
        // `array` inside a docblock has no value type, so `array[]` reads as `array<int, array>` and
        // the inner one trips `missingType.iterableValue`.
        ->toContain('@param array<string, mixed>[] $provenance')
        ->not->toContain('App\Generated\Data\Location');
});

it('emits syntactically valid PHP', function () {
    $model = (new CodegenModel)
        ->enum('Kind', ['a', 'b'])
        ->record('Thing', fn (RecordType $r) => $r
            ->field('kind', Type::ref('Kind'))
            ->field('meta', Type::mapOf(Type::primitive(Primitive::String)))
            ->field('either', Type::union([Type::primitive(Primitive::String), Type::ref('Kind')])));

    foreach ((new DataClassGenerator)->invoke(['model' => $model->toArray(), 'options' => []])['files'] as $rel => $code) {
        expect(phpLint($code))->toContain('No syntax errors detected');
    }
});

it('emits camelCase properties with MapName for non-camel wire keys', function () {
    // Owner direction (root 036dff3b): a non-camel wire key becomes a camelCase property carried by
    // #[MapName]; a key that is already camelCase is left exactly as-is (no attribute).
    $model = (new CodegenModel)
        ->record('Mapped', fn (RecordType $r) => $r
            ->field('max_depth', Type::primitive(Primitive::Int))
            ->field('ref_id', Type::primitive(Primitive::String))
            ->field('already_camel', Type::primitive(Primitive::String))
            ->field('plainCamel', Type::primitive(Primitive::String)));

    $mapped = (new DataClassGenerator)->invoke(['model' => $model->toArray(), 'options' => []])['files']['Mapped.php'];

    expect($mapped)
        ->toContain('use Spatie\LaravelData\Attributes\MapName;')
        ->toContain("#[MapName('max_depth')]")
        ->toContain('public int $maxDepth')
        ->toContain("#[MapName('ref_id')]")
        ->toContain('public string $refId')
        ->toContain("#[MapName('already_camel')]")
        ->toContain('public string $alreadyCamel')
        // already-camel wire key: property verbatim, no attribute.
        ->toContain('public string $plainCamel')
        ->not->toContain("#[MapName('plainCamel')]")
        // the non-camel wire names never survive as PHP property names.
        ->not->toContain('$max_depth')
        ->not->toContain('$ref_id')
        ->not->toContain('$already_camel');
});

it('round-trips a mapped property through from() and toArray()', function () {
    $this->app->register(LaravelDataServiceProvider::class);

    $model = (new CodegenModel)
        ->record('RoundTrip', fn (RecordType $r) => $r
            ->field('max_depth', Type::primitive(Primitive::Int))
            ->field('ref_id', Type::primitive(Primitive::String)));

    $src = (new DataClassGenerator)->invoke([
        'model' => $model->toArray(),
        'options' => ['namespace' => 'Rushing\\Codegen\\Laravel\\Tests\\Generated'],
    ])['files']['RoundTrip.php'];

    $tmp = tempnam(sys_get_temp_dir(), 'rt').'.php';
    file_put_contents($tmp, $src);
    require $tmp;
    @unlink($tmp);

    $class = 'Rushing\\Codegen\\Laravel\\Tests\\Generated\\RoundTrip';
    $dto = $class::from(['max_depth' => 3, 'ref_id' => 'r-1']);

    // from() hydrates by the wire key; the property is the camelCase name.
    expect($dto->maxDepth)->toBe(3)
        ->and($dto->refId)->toBe('r-1')
        // toArray() emits the wire key again, which is what storage/transport carries.
        ->and($dto->toArray())->toMatchArray(['max_depth' => 3, 'ref_id' => 'r-1']);
});

it('fails loudly when two wire keys camelize to the same property', function () {
    // `foo_bar` and `fooBar` both camelize to `fooBar`; generation cannot honour both with one
    // property, so it throws rather than silently drop or merge them.
    $model = (new CodegenModel)
        ->record('Collider', fn (RecordType $r) => $r
            ->field('foo_bar', Type::primitive(Primitive::String))
            ->field('fooBar', Type::primitive(Primitive::String)));

    expect(fn () => (new DataClassGenerator)->invoke(['model' => $model->toArray(), 'options' => []]))
        ->toThrow(RuntimeException::class, 'both map to PHP property "$fooBar"');
});
