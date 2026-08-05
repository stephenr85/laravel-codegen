<?php

use Rushing\Codegen\Hydration\JsonSchemaHydrator;
use Rushing\Codegen\Laravel\Generators\DataClassGenerator;
use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Primitive;
use Rushing\Codegen\Model\RecordType;
use Rushing\Codegen\Model\Type;

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
