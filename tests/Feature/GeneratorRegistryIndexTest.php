<?php

use Rushing\Codegen\GeneratorRegistry;
use Rushing\Codegen\Generators\ClosureGenerator;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryIndex;

it('describes the generator registry down into the index from its own provider', function () {
    expect(app(RegistryIndex::class)->owner('codegen.generators'))
        ->toBeInstanceOf(GeneratorRegistry::class);
});

it('routes a generator key through the index by longest prefix', function () {
    app(GeneratorRegistry::class)->register(
        new ClosureGenerator('fake', fn (array $m, array $o) => ['x.txt' => 'x']),
    );

    $index = app(RegistryIndex::class);
    $store = $index->routeTo(Key::parse('codegen.generators.fake'));

    expect($store)->not->toBeNull()
        ->and($store->has('codegen.generators.fake'))->toBeTrue();
});
