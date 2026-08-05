<?php

return [

    // Where generated files are written. Each generator's returned paths are relative to this.
    'out_dir' => base_path('resources/generated'),

    // The stacks to run by default (registry keys / Generator::stack()). Empty = every registered
    // stack. `codegen:generate --stack=ts-client --stack=php-saloon` overrides this.
    'stacks' => [],

    // Per-stack options passed through as the generator's input['options'].
    'options' => [
        // 'ts-client' => ['emit_stores' => false],
    ],

    // Post-write formatters, best-effort (skipped if the binary is missing — a generated tree is
    // still valid unformatted). Each entry: a path glob + the argv to run against matched files.
    'formatters' => [
        // ['match' => '*.ts', 'command' => ['npx', 'prettier', '--write']],
        // ['match' => '*.php', 'command' => ['vendor/bin/pint']],
    ],

];
