<?php

namespace Rushing\Codegen\Laravel\Console;

use Illuminate\Console\Command;
use Rushing\Codegen\Contracts\Generator;
use Rushing\Codegen\GeneratorRegistry;
use Rushing\Codegen\Laravel\Contracts\ModelSource;
use Rushing\Codegen\Laravel\FileWriter;
use Rushing\Codegen\Model\CodegenModel;

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
        $requested = $this->option('stack')
            ?: (config('codegen.stacks') ?: $registry->stacks());

        $stacks = array_values(array_filter($requested, fn ($s) => $registry->has($s)));

        if ($stacks === []) {
            $this->warn('No matching registered stacks to generate.');

            return self::SUCCESS;
        }

        $formatters = (array) config('codegen.formatters', []);

        // The default host model, built once and shared by every stack that does not override its source.
        $defaultModel = null;

        /** @var array<string, string> $failures stack => the throwable's message */
        $failures = [];

        foreach ($stacks as $stack) {
            try {
                $this->runStack($stack, $registry, $source, $formatters, $defaultModel);
            } catch (\Throwable $e) {
                // ONE BAD STACK MUST NOT SILENCE THE REST. Generation is used as a verification
                // instrument, not just an output step: a host regenerates to find out whether its
                // declarations still project. When a fatal in the first stack aborted the command,
                // every stack after it simply never ran — and because the abort lands at the end of a
                // long, successful-looking log, the truncation reads as a completed run. That is a
                // silent false negative in the exact tool the estate uses to detect drift.
                //
                // So a stack failure is COLLECTED and the loop continues; the failures are reported
                // together at the end and the command exits non-zero. Loud and complete, rather than
                // loud and truncated.
                $failures[$stack] = $e->getMessage();
                $this->components->error(sprintf('%s: %s', $stack, $e->getMessage()));
            }
        }

        if ($failures !== []) {
            $this->newLine();
            $this->error(sprintf(
                '%d of %d stack(s) failed: %s',
                count($failures),
                count($stacks),
                implode(', ', array_keys($failures)),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Generate and write one stack. Extracted so {@see handle()} can isolate a per-stack failure
     * without the loop body's locals leaking across iterations.
     *
     * @param  array<int, array<string, mixed>>  $formatters
     */
    private function runStack(
        string $stack,
        GeneratorRegistry $registry,
        ModelSource $source,
        array $formatters,
        ?CodegenModel &$defaultModel,
    ): void {
        $options = (array) (config("codegen.options.{$stack}") ?? []);

        // A stack may pin its OWN model source (`codegen.options.{stack}.source` = a ModelSource class)
        // — e.g. an OpenAPI-fed source for a from-spec SDK stack, distinct from the app's default
        // route-manifest source. The seam stays "the host decides where the model comes from", now at
        // per-stack granularity. Each source's model is validated before its generator runs.
        $model = isset($options['source']) && is_string($options['source'])
            ? app($options['source'])->model()->validate()
            : ($defaultModel ??= $source->model()->validate());

        // Resolve the write target per stack: a stack may pin its own `out_dir` under
        // `codegen.options.{$stack}.out_dir`, else it falls back to the global `codegen.out_dir`.
        // `out_dir` is a host/write concern — the generator returns relative paths and ignores it —
        // so the FileWriter, not the generator, owns where those paths land.
        $outDir = (string) ($options['out_dir'] ?? config('codegen.out_dir'));

        $writer = new FileWriter($outDir, $formatters);

        $files = $registry->generate($stack, $model, $options);
        $written = $writer->write($files);

        $this->info(sprintf('%s: %d file(s)', $stack, count($written)));
    }
}
