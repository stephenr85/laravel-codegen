<?php

namespace Rushing\Codegen\Laravel\Generators;

use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Rushing\Codegen\Contracts\Generator;
use Rushing\Popcorn\Binding;

/**
 * The import-flow emitter (codegen-substrate #07): turns the canonical model's records + enums into
 * PHP DTO classes — by default `spatie/laravel-data` `Data` classes. Paired with `JsonSchemaHydrator`
 * (#03) it closes the round-trip you asked for: an `App\Data` DTO → JSON Schema → `CodegenModel` →
 * back to a `Data` class. It is the generic *emit* counterpart to the app-specific TS/Saloon drivers,
 * and `rushing/laravel-codegen`'s first shipped generic emitter.
 *
 * The syntactic PHP builder (nette/php-generator) lives inside this driver; the model stays semantic.
 * The base class is an option (default `Spatie\LaravelData\Data`) referenced as a string, so the
 * package takes no hard dependency on spatie/laravel-data.
 *
 * Type-kind → PHP mapping is best-effort and honest at its edges: unions collapse to `mixed`,
 * list/map to `array` (element type noted in a docblock). A driver that wants richer output
 * (DataCollection, union types) specializes from here.
 */
class DataClassGenerator implements Generator
{
    /** @var array<string, mixed> */
    private array $options = [];

    public function name(): string
    {
        return 'php-data';
    }

    public function stack(): string
    {
        return 'php-data';
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
        $this->options = $input['options'] ?? [];
        $model = $input['model'] ?? [];
        $printer = new PsrPrinter;

        $files = [];

        foreach ($model['enums'] ?? [] as $enum) {
            $files[$enum['name'].'.php'] = $printer->printFile($this->buildEnum($enum));
        }

        foreach ($model['records'] ?? [] as $record) {
            $files[$record['name'].'.php'] = $printer->printFile($this->buildRecord($record));
        }

        return ['files' => $files];
    }

    private function namespace(): string
    {
        return (string) ($this->options['namespace'] ?? 'App\\Data');
    }

    private function baseClass(): string
    {
        return (string) ($this->options['base_class'] ?? 'Spatie\\LaravelData\\Data');
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function buildRecord(array $record): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes(false);
        $ns = $file->addNamespace($this->namespace());

        $base = $this->baseClass();
        $ns->addUse($base);
        $class = $ns->addClass($record['name'])->setExtends($base);

        if ($record['doc'] ?? null) {
            $class->addComment((string) $record['doc']);
        }

        $ctor = $class->addMethod('__construct');

        foreach ($record['fields'] ?? [] as $field) {
            [$hint, $nullable, $docHint] = $this->phpType($field['type']);

            $param = $ctor->addPromotedParameter($field['name'])
                ->setType($hint === 'mixed' ? null : ltrim($hint, '?'))
                ->setNullable($nullable && $hint !== 'mixed')
                ->setPublic();

            if ($nullable) {
                $param->setDefaultValue(null);
            }

            if ($docHint !== null) {
                $ctor->addComment("@param {$docHint} \${$field['name']}");
            }
        }

        return $file;
    }

    /**
     * @param  array<string, mixed>  $enum
     */
    private function buildEnum(array $enum): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes(false);
        $ns = $file->addNamespace($this->namespace());

        $type = $ns->addEnum($enum['name']);
        $type->setType(($enum['backing'] ?? 'string') === 'int' ? 'int' : 'string');

        if ($enum['doc'] ?? null) {
            $type->addComment((string) $enum['doc']);
        }

        foreach ($enum['cases'] ?? [] as $case) {
            $type->addCase($this->caseName($case), $case);
        }

        return $file;
    }

    /**
     * Map a serialized type to [phpTypeHint, nullable, docHint].
     *
     * @param  array<string, mixed>  $type
     * @return array{0: string, 1: bool, 2: ?string}
     */
    private function phpType(array $type): array
    {
        return match ($type['kind'] ?? null) {
            'primitive' => [$this->primitive($type['primitive'] ?? 'json'), false, null],
            'ref' => [$this->internalHint((string) ($type['ref'] ?? '')), false, null],
            'external' => [$this->externalHint((string) ($type['ref'] ?? '')), false, null],
            'list' => ['array', false, $this->elementDoc($type['inner'] ?? null).'[]'],
            'map' => ['array', false, 'array<string, '.$this->elementDoc($type['value'] ?? null).'>'],
            'optional' => $this->optional($type['inner'] ?? []),
            'union' => ['mixed', false, null],
            default => ['mixed', false, null],
        };
    }

    /**
     * @param  array<string, mixed>  $inner
     * @return array{0: string, 1: bool, 2: ?string}
     */
    private function optional(array $inner): array
    {
        [$hint, , $doc] = $this->phpType($inner);

        return [$hint, true, $doc];
    }

    private function primitive(string $p): string
    {
        return match ($p) {
            'int' => 'int',
            'float' => 'float',
            'bool' => 'bool',
            'timestamp' => '\\DateTimeInterface',
            'json' => 'array',
            default => 'string',   // string, bytes, uuid
        };
    }

    /**
     * An internal ref lives in the model's own namespace. We hand nette the FQCN so it renders the
     * sibling relatively (`Status`), not as a global `\Status`.
     */
    private function internalHint(string $ref): string
    {
        return '\\'.trim($this->namespace(), '\\').'\\'.$ref;
    }

    /**
     * An external ref (`App.Data.User`) is a real FQCN. nette renders it relative when it happens to
     * share the file's namespace, else fully-qualified — both correct.
     */
    private function externalHint(string $ref): string
    {
        return '\\'.ltrim(str_replace('.', '\\', $ref), '\\');
    }

    /**
     * @param  array<string, mixed>|null  $type
     */
    private function elementDoc(?array $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        [$hint] = $this->phpType($type);

        if ($hint === 'mixed') {
            return 'mixed';
        }

        // A `json` element is a bare `array` in the SIGNATURE, which is right — but a bare `array`
        // inside a docblock has no value type, so `array[]` reads as `array<int, array>` and a static
        // analyser flags the inner one (`missingType.iterableValue`). An unconstrained JSON object is
        // honestly `array<string, mixed>`; say so. The signature is unaffected — only the hint widens.
        if ($hint === 'array') {
            return 'array<string, mixed>';
        }

        return $this->docName($hint);
    }

    /**
     * Render a type hint as it must read inside a docblock in the generated file's namespace.
     *
     * A docblock is raw text — nette resolves the *signature* types against the file's `use` set,
     * but never the comment. So an unrooted `App\Data\Foo` in a file that is itself
     * `namespace App\Data` resolves to `App\Data\App\Data\Foo`, a class that does not exist.
     * A sibling in the file's own namespace therefore collapses to its short name (matching how
     * nette prints the same class in the signature); everything else keeps its leading backslash.
     */
    private function docName(string $hint): string
    {
        $hint = ltrim($hint, '?');

        if (! str_starts_with($hint, '\\')) {
            return $hint;   // scalar or `array`
        }

        $prefix = '\\'.trim($this->namespace(), '\\').'\\';

        if (str_starts_with($hint, $prefix)) {
            $short = substr($hint, strlen($prefix));

            if (! str_contains($short, '\\')) {
                return $short;
            }
        }

        return $hint;
    }

    private function caseName(string|int $case): string
    {
        $studly = Str::studly((string) $case);

        return $studly === '' || is_numeric($studly[0] ?? '') ? 'Case'.$case : $studly;
    }
}
