<?php

use Rushing\Codegen\Laravel\Tests\ConfiguredGeneratorsTestCase;
use Rushing\Codegen\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature/GenerateCommandTest.php');
uses(TestCase::class)->in('Feature/RegistryReflectingModelSourceTest.php');
uses(TestCase::class)->in('Feature/GeneratorRegistryIndexTest.php');
uses(TestCase::class)->in('Unit');

// Config-driven discovery (codegen-substrate #04) needs `codegen.generators` seeded *before* the
// provider boots, so this suite binds a TestCase whose getEnvironmentSetUp() does exactly that.
uses(ConfiguredGeneratorsTestCase::class)->in('Feature/ConfiguredGenerators');
