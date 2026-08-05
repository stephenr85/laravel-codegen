<?php

namespace Rushing\Codegen\Laravel;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Owns the disk side of generation (codegen-substrate #04): generators return a file map and stay
 * pure, this writes it under `out_dir` and runs best-effort formatters. Centralizing the write here is
 * what lets a subprocess/other-language generator be sandboxed (it only ever hands bytes back — it
 * never touches the host filesystem).
 */
class FileWriter
{
    /**
     * @param  array<int, array{match: string, command: array<int, string>}>  $formatters
     */
    public function __construct(
        private string $outDir,
        private array $formatters = [],
    ) {}

    /**
     * Write a generator's file map (relative path => contents) and format matched files. Returns the
     * absolute paths written.
     *
     * @param  array<string, string>  $files
     * @return string[]
     */
    public function write(array $files): array
    {
        $written = [];

        foreach ($files as $relative => $contents) {
            $path = rtrim($this->outDir, '/').'/'.ltrim($relative, '/');
            $dir = dirname($path);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, recursive: true);
            }

            file_put_contents($path, $contents);
            $written[] = $path;
        }

        $this->format($written);

        return $written;
    }

    /**
     * @param  string[]  $paths
     */
    private function format(array $paths): void
    {
        foreach ($this->formatters as $formatter) {
            $matched = array_values(array_filter(
                $paths,
                fn (string $p) => Str::is($formatter['match'], basename($p)),
            ));

            if ($matched === []) {
                continue;
            }

            // Best-effort: a missing formatter binary must not fail the generation.
            Process::run([...$formatter['command'], ...$matched]);
        }
    }
}
