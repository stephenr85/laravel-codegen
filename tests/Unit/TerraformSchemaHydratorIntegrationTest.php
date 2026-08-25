<?php

use Rushing\Codegen\Hydration\TerraformSchemaHydrator;
use Rushing\Codegen\Laravel\Generators\DataClassGenerator;

/**
 * Fixture is a *live* capture, not hand-authored: `tofu providers schema -json` run against
 * `splicewire/laravel-beam-provision`'s `tofu/modules/tenant-database` module (hashicorp/google
 * v7.44.0), then filtered down to the four resource types gcp-cloud-run-provisioning ticket 07's
 * modules actually use — see ticket 08's Answer for the exact `jq` filter.
 */
function tofuGoogleProviderSchema(): array
{
    return json_decode(
        file_get_contents(__DIR__.'/../Fixtures/tofu-google-provider-schema.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

it('round-trips: a live tofu providers schema -json document -> model -> Data class, unchanged DataClassGenerator', function () {
    $model = (new TerraformSchemaHydrator)->hydrate(tofuGoogleProviderSchema())->validate();

    $files = (new DataClassGenerator)->invoke(['model' => $model->toArray(), 'options' => []])['files'];

    expect($files)->toHaveKeys([
        'GoogleCloudRunV2Service.php',
        'GoogleSqlDatabaseInstance.php',
        'GoogleSqlDatabase.php',
        'GoogleSqlUser.php',
    ]);

    // Nested blocks survive as typed, named, sibling classes rather than collapsing to Primitive::Json.
    expect($files)->toHaveKeys([
        'GoogleSqlDatabaseInstanceSettings.php',
        'GoogleSqlDatabaseInstanceSettingsIpConfiguration.php',
        'GoogleSqlDatabaseInstanceSettingsBackupConfiguration.php',
    ]);

    expect($files['GoogleSqlDatabaseInstance.php'])
        ->toContain('class GoogleSqlDatabaseInstance extends Data')
        ->toContain('public string $database_version')                                  // required
        ->toContain('@param GoogleSqlDatabaseInstanceSettings[] $settings')             // nested block -> typed ref, not json; sibling reads short
        ->toContain('public ?array $settings = null');                                  // list-nested -> optional (max_items: 1, no min_items)

    // 83 records from these four resource types alone — one `php -l` subprocess per file is minutes
    // of wall-clock. Every file after the first has its opening `<?php` stripped so the whole batch
    // lints as a single PHP stream in one process; `-l` only checks syntax, so the resulting
    // multi-class file (never executed) doesn't trip a redeclaration error.
    $batch = implode("\n", array_map(
        fn ($i, $code) => $i === 0 ? $code : preg_replace('/^<\?php\s*/', '', $code, 1),
        array_keys(array_values($files)),
        array_values($files),
    ));
    $tmp = tempnam(sys_get_temp_dir(), 'terraform-datagen').'.php';
    file_put_contents($tmp, $batch);
    $lint = (string) shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1');
    @unlink($tmp);

    expect($lint)->toContain('No syntax errors detected');
});
