<?php

namespace Rushing\Codegen\Laravel\Contracts;

use Rushing\Codegen\Model\CodegenModel;

/**
 * The host seam that supplies the {@see CodegenModel} the command generates from. The binding owns
 * the *plumbing* (resolve generators, invoke, write, format); the *model* is host-specific — in the
 * app it is assembled by hydrating JSON Schema (codegen-substrate #03) and layering the route
 * manifest's operations on top. A host binds one implementation; tests bind a fake.
 *
 * Kept a one-method port so the "where does the model come from" decision stays the host's, not the
 * binding's — the same reason `RouteManifestSource` is host-bound in laravel-beam today.
 */
interface ModelSource
{
    public function model(): CodegenModel;
}
