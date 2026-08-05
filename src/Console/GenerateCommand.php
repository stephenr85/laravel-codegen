<?php

namespace Rushing\Codegen\Laravel\Console;

use Illuminate\Console\Command;
use Rushing\Codegen\Contracts\Generator;
use Rushing\Codegen\GeneratorRegistry;
use Rushing\Codegen\Laravel\Contracts\ModelSource;
use Rushing\Codegen\Laravel\FileWriter;

/**
 * The orchestrator (codegen-substrate #04): resolve the host's {@see ModelSource}, validate the model
 * once, then for each requested stack invoke its {@see Generator} and write
 * the returned files. It does not know or care whether a stack is a native PHP emitter or a subprocess
 * toolchain — that is the popcorn binding's business. This is the shape `GenerateAssetsCommand`
 * refactors toward.
 */
class GenerateCommand extends Command
{
    protected $signature = 'codegen:generate {--stack=* : Stacks to generate (default: config or all registered)}';

    protected $description = 'Generate code for registered stacks from the host model.';

    public function handle(GeneratorRegistry $registry, ModelSource $source): int
    {
        $model = $source->model()->validate();

        $requested = $this->option('stack')
            ?: (config('codegen.stacks') ?: $registry->stacks());

        $stacks = array_values(array_filter($requested, fn ($s) => $registry->has($s)));

        if ($stacks === []) {
            $this->warn('No matching registered stacks to generate.');

            return self::SUCCESS;
        }

        $writer = new FileWriter(
            (string) config('codegen.out_dir'),
            (array) config('codegen.formatters', []),
        );

        foreach ($stacks as $stack) {
            $options = (array) (config("codegen.options.{$stack}") ?? []);
            $files = $registry->generate($stack, $model, $options);
            $written = $writer->write($files);

            $this->info(sprintf('%s: %d file(s)', $stack, count($written)));
        }

        return self::SUCCESS;
    }
}
