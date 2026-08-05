<?php

use Rushing\Codegen\Laravel\Generators\ProcessGenerator;

it('runs an out-of-process toolchain: model in on stdin, files out on stdout', function () {
    // A real subprocess (php here, but it stands in for a Rust/Node/jco toolchain): it reads the
    // serialized model from stdin and returns the {"files": …} contract on stdout. This proves the
    // "run another language's codegen package" path reaches the same seam a native emitter uses.
    $script = '$in = json_decode(file_get_contents("php://stdin"), true);'
        .'echo json_encode(["files" => ["count.txt" => (string) count($in["model"]["records"])]]);';

    $generator = new ProcessGenerator('proc', [PHP_BINARY, '-r', $script]);

    $out = $generator->invoke([
        'model' => ['records' => [['name' => 'A'], ['name' => 'B']]],
        'options' => [],
    ]);

    expect($out)->toBe(['files' => ['count.txt' => '2']]);
});

it('throws when the toolchain exits non-zero', function () {
    $generator = new ProcessGenerator('proc', [PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(3);']);

    expect(fn () => $generator->invoke(['model' => [], 'options' => []]))
        ->toThrow(RuntimeException::class, 'exited 3');
});
