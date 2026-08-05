<?php

namespace Rushing\Codegen\Laravel\Generators;

use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Rushing\Codegen\Contracts\Generator;
use Rushing\Popcorn\Binding;

/**
 * A generator answered by an out-of-process toolchain — a Rust codegen crate, `openapi-generator`,
 * `jco`/`wasmtime` for WIT bindings, anything (codegen-substrate #02, #04). The serialized model +
 * options cross as JSON on stdin; the toolchain returns `{"files": {path: contents}}` on stdout. This
 * is how "run another language's codegen package" (the popcorn intuition) reaches the same seam a
 * native PHP emitter uses — the registry, callers, and the command see no difference.
 *
 * Locality vs. mechanism are different axes (popcorn ADR-0001): this still reports
 * {@see Binding::Local} — a sandboxed subprocess is local, "sandboxed" is a Grant profile, not a
 * Binding. For a fully-sandboxed run with a deny-by-default Grant, wrap a popcorn `RunnerInvocable`
 * instead; this class is the pragmatic argv-on-PATH form.
 */
class ProcessGenerator implements Generator
{
    /**
     * @param  list<string>  $command  argv to spawn (e.g. ['node', 'gen-wit.mjs'] or ['./target/release/gen'])
     */
    public function __construct(
        private string $stack,
        private array $command,
        private ?int $timeout = 120,
    ) {}

    public function name(): string
    {
        return $this->stack;
    }

    public function stack(): string
    {
        return $this->stack;
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invoke(array $input): array
    {
        try {
            $payload = json_encode($input, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("codegen[{$this->stack}]: could not encode model — {$e->getMessage()}", previous: $e);
        }

        $result = Process::timeout($this->timeout ?? 120)->input($payload)->run($this->command);

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'codegen[%s]: toolchain exited %d — %s',
                $this->stack,
                $result->exitCode() ?? -1,
                trim($result->errorOutput()) ?: trim($result->output()),
            ));
        }

        try {
            $decoded = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("codegen[{$this->stack}]: toolchain did not return valid JSON — {$e->getMessage()}", previous: $e);
        }

        return ['files' => $decoded['files'] ?? []];
    }
}
