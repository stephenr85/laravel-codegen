<?php

return [

    // Where generated files are written. Each generator's returned paths are relative to this.
    'out_dir' => base_path('resources/generated'),

    // The stacks to run by default (registry keys / Generator::stack()). Empty = every registered
    // stack. `codegen:generate --stack=ts-client --stack=php-saloon` overrides this.
    'stacks' => [],

    // Generator classes (Rushing\Codegen\Contracts\Generator) to auto-register at boot. Each is
    // resolved from the container (so generators may declare constructor deps) and registered into
    // the GeneratorRegistry. Composes with manual registration — a host can still call
    // app(GeneratorRegistry::class)->register(...) alongside this list.
    'generators' => [
        // \App\Codegen\SomeGenerator::class,
    ],

    // Per-stack options passed through as the generator's input['options']. A reserved `out_dir` key
    // overrides the global `out_dir` above for that stack's write target only (the generator still
    // returns relative paths and ignores it — it's a host/write concern).
    'options' => [
        // 'ts-client' => ['emit_stores' => false, 'out_dir' => resource_path('js/generated')],
    ],

    // Post-write formatters, best-effort (skipped if the binary is missing — a generated tree is
    // still valid unformatted). Each entry: a path glob + the argv to run against matched files.
    'formatters' => [
        // ['match' => '*.ts', 'command' => ['npx', 'prettier', '--write']],
        // ['match' => '*.php', 'command' => ['vendor/bin/pint']],
    ],

];
