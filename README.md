# rushing/laravel-codegen

Laravel binding for [`rushing/codegen`](../codegen). It supplies the plumbing so a host only has to
(a) register generators and (b) bind a `ModelSource`:

- **`CodegenServiceProvider`** — binds the `GeneratorRegistry` (over popcorn's `InvocableRegistry`),
  publishes `config/codegen.php`, registers the command.
- **`codegen:generate {--stack=*}`** — resolves the host `ModelSource`, validates the model once, then
  invokes each requested stack and writes its files.
- **`FileWriter`** — owns disk-write + best-effort formatters, so **generators stay pure** (bytes out,
  no filesystem) and a subprocess generator can be sandboxed.
- **`ProcessGenerator`** — the out-of-process path: model-as-JSON on stdin, `{"files": …}` on stdout.
  This is how "run another language's codegen package" reaches the same seam as a native PHP emitter.

Config: `out_dir`, `stacks` (default set), per-stack `options`, and best-effort `formatters`
(path-glob → argv, e.g. Prettier for `*.ts`, Pint for `*.php`).

The host (the app) registers its `ts-client` / `php-saloon` generators and binds a `ModelSource` that
hydrates JSON Schema + layers the route manifest. This package binds none of that.

Status: skeleton (codegen-substrate #04). See `.scratch/codegen-substrate/` in the app repo.
