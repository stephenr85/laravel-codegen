> You are in **rushing/laravel-codegen** — the Laravel binding for `rushing/codegen`: an artisan command that resolves generators from the registry, invokes them against a host-supplied model, and writes + formats the returned files.

Native emitters and subprocess/other-language toolchains sit behind one seam (`Generator` contract). This package supplies plumbing only — a host registers its own generators and binds its own `ModelSource`.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
