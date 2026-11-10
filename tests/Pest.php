<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class)->in(__DIR__);

/**
 * Recreates the table from the migration stub. On MySQL/PostgreSQL (CI) tables survive between tests,
 * so the tables the import command may leave behind are dropped first.
 */
function migratePostalCodesTable(): void
{
    $table = config('jp-postal.drivers.database.table', 'jp_postal_codes');

    foreach ([$table, "{$table}_importing", "{$table}_previous"] as $name) {
        Schema::dropIfExists($name);
    }

    (include __DIR__.'/../database/migrations/create_jp_postal_codes_table.php.stub')->up();
}

function fixturePath(string $name = 'utf_ken_all_sample.csv'): string
{
    return __DIR__.'/Fixtures/'.$name;
}

/**
 * @param  array<string, string>  $entries  entry name => contents
 */
function makeZip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'jp-postal-test-').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();

    return $path;
}

function makeCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'jp-postal-test-');
    file_put_contents($path, $contents);

    return $path;
}
