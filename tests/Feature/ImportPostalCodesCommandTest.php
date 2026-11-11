<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const VALID_ROW = '13101,"100  ","1000001","トウキョウト","チヨダク","チヨダ","東京都","千代田区","千代田",0,0,0,0,0,0';
const FIXTURE_ROWS = 15;

pest()->group('database');

beforeEach(function (): void {
    Http::preventStrayRequests();
    migratePostalCodesTable();
});

function seedExistingRow(): void
{
    DB::table('jp_postal_codes')->insert([
        'zipcode' => '9999999',
        'local_government_code' => '99999',
        'prefecture_code' => '99',
        'prefecture' => '旧',
        'city' => '旧',
        'town' => '旧',
    ]);
}

function expectOnlyExistingRow(): void
{
    expect(DB::table('jp_postal_codes')->pluck('zipcode')->all())->toBe(['9999999'])
        ->and(Schema::hasTable('jp_postal_codes_importing'))->toBeFalse()
        ->and(Schema::hasTable('jp_postal_codes_previous'))->toBeFalse();
}

it('imports a local csv file', function (): void {
    $this->artisan('jp-postal:import', ['--file' => fixturePath()])
        ->expectsOutputToContain('Imported '.FIXTURE_ROWS.' postal codes.')
        ->assertExitCode(Command::SUCCESS);

    expect(DB::table('jp_postal_codes')->count())->toBe(FIXTURE_ROWS);
});

it('stores normalized values', function (): void {
    $this->artisan('jp-postal:import', ['--file' => fixturePath()])->assertSuccessful();

    $row = fn (string $zipcode) => (array) DB::table('jp_postal_codes')->where('zipcode', $zipcode)->first();

    expect($row('1000001'))->toMatchArray([
        'zipcode' => '1000001',
        'local_government_code' => '13101',
        'prefecture_code' => '13',
        'prefecture' => '東京都',
        'city' => '千代田区',
        'town' => '千代田',
        'prefecture_kana' => 'トウキョウト',
        'city_kana' => 'チヨダク',
        'town_kana' => 'チヨダ',
    ])
        ->and($row('0600000'))->toMatchArray(['town' => '', 'town_kana' => null, 'prefecture_code' => '01'])
        ->and($row('1600023'))->toMatchArray(['town' => '西新宿', 'town_kana' => 'ニシシンジュク'])
        ->and($row('7660002'))->toMatchArray(['town' => '', 'town_kana' => null])
        ->and($row('3970201'))->toMatchArray(['town' => '', 'town_kana' => null])
        ->and($row('5220317'))->toMatchArray(['town' => '一円', 'town_kana' => 'イチエン'])
        ->and($row('0287915'))->toMatchArray(['town' => '種市', 'town_kana' => 'タネイチ'])
        ->and($row('4411324'))->toMatchArray(['town' => '', 'town_kana' => null]);
});

it('replaces existing data and cleans up working tables', function (): void {
    seedExistingRow();

    $this->artisan('jp-postal:import', ['--file' => fixturePath()])->assertSuccessful();

    expect(DB::table('jp_postal_codes')->where('zipcode', '9999999')->exists())->toBeFalse()
        ->and(DB::table('jp_postal_codes')->count())->toBe(FIXTURE_ROWS)
        ->and(Schema::hasTable('jp_postal_codes_importing'))->toBeFalse()
        ->and(Schema::hasTable('jp_postal_codes_previous'))->toBeFalse();
});

it('can import repeatedly', function (): void {
    $this->artisan('jp-postal:import', ['--file' => fixturePath()])->assertSuccessful();
    $this->artisan('jp-postal:import', ['--file' => fixturePath()])->assertSuccessful();

    expect(DB::table('jp_postal_codes')->count())->toBe(FIXTURE_ROWS)
        ->and(Schema::hasIndex('jp_postal_codes', ['zipcode']))->toBeTrue();
});

it('imports a local zip file', function (): void {
    $zip = makeZip(['readme.txt' => 'ignored', 'utf_ken_all.csv' => file_get_contents(fixturePath())]);

    $this->artisan('jp-postal:import', ['--file' => $zip])->assertSuccessful();

    expect(DB::table('jp_postal_codes')->count())->toBe(FIXTURE_ROWS);
});

it('strips a byte order mark', function (): void {
    $this->artisan('jp-postal:import', ['--file' => makeCsv("\u{FEFF}".VALID_ROW."\r\n")])->assertSuccessful();

    expect(DB::table('jp_postal_codes')->value('local_government_code'))->toBe('13101');
});

it('downloads the configured source url by default', function (): void {
    $zip = makeZip(['utf_ken_all.csv' => file_get_contents(fixturePath())]);
    Http::fake(['https://www.post.japanpost.jp/*' => Http::response(file_get_contents($zip))]);

    $this->artisan('jp-postal:import')->assertSuccessful();

    expect(DB::table('jp_postal_codes')->count())->toBe(FIXTURE_ROWS);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.post.japanpost.jp/service/search/zipcode/download/utf/zip/utf_ken_all.zip');
});

it('downloads from the url option', function (): void {
    $zip = makeZip(['utf_ken_all.csv' => VALID_ROW."\r\n"]);
    Http::fake(['https://example.test/*' => Http::response(file_get_contents($zip))]);

    $this->artisan('jp-postal:import', ['--url' => 'https://example.test/data.zip'])->assertSuccessful();

    expect(DB::table('jp_postal_codes')->count())->toBe(1);
});

it('fails when the download fails', function (): void {
    seedExistingRow();
    Http::fake(['https://example.test/*' => Http::response('Not Found', 404)]);

    $this->artisan('jp-postal:import', ['--url' => 'https://example.test/data.zip'])
        ->expectsOutputToContain('status 404')
        ->assertFailed();

    expectOnlyExistingRow();
});

it('fails when the download cannot connect', function (): void {
    seedExistingRow();
    Http::fake(['https://example.test/*' => Http::failedConnection()]);

    $this->artisan('jp-postal:import', ['--url' => 'https://example.test/data.zip'])
        ->expectsOutputToContain('Could not download')
        ->assertFailed();

    expectOnlyExistingRow();
});

it('rejects invalid files and keeps the existing data', function (Closure $file, string $message): void {
    seedExistingRow();

    $this->artisan('jp-postal:import', ['--file' => $file()])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expectOnlyExistingRow();
})->with([
    'missing file' => [fn () => '/nonexistent/utf_ken_all.csv', 'does not exist'],
    'empty file' => [fn () => makeCsv(''), 'does not contain any postal codes'],
    'too few columns' => [fn () => makeCsv("13101,\"100  \",\"1000001\"\r\n"), 'Line 1 has 3 columns; expected 15.'],
    'invalid postal code on a later line' => [fn () => makeCsv(VALID_ROW."\r\n".str_replace('"1000001"', '"100-001"', VALID_ROW)."\r\n"), 'Line 2 does not contain a valid'],
    'shift_jis' => [fn () => makeCsv(mb_convert_encoding(VALID_ROW."\r\n", 'SJIS-win', 'UTF-8')), 'is not UTF-8'],
    'zip without csv' => [fn () => makeZip(['readme.txt' => 'nothing here']), 'does not contain a CSV file'],
    'broken zip' => [fn () => makeCsv("PK\x03\x04broken"), 'not a valid ZIP archive'],
]);

it('expands a leading ~/ in --file to the home directory', function (): void {
    $home = sys_get_temp_dir().'/jp-postal-home-'.bin2hex(random_bytes(4));
    mkdir("{$home}/data", recursive: true);
    copy(fixturePath(), "{$home}/data/utf_ken_all.csv");
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $home;

    try {
        // The shell leaves 「--file=~/...」 untouched, so the command receives the tilde itself.
        $this->artisan('jp-postal:import', ['--file' => '~/data/utf_ken_all.csv'])
            ->expectsOutputToContain("Importing [{$home}/data/utf_ken_all.csv]")
            ->assertSuccessful();
    } finally {
        $_SERVER['HOME'] = $originalHome;
        unlink("{$home}/data/utf_ken_all.csv");
        rmdir("{$home}/data");
        rmdir($home);
    }

    expect(DB::table('jp_postal_codes')->count())->toBe(FIXTURE_ROWS);
});

it('reports the expanded path when a ~/ file does not exist', function (): void {
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = '/home/someone';

    try {
        $this->artisan('jp-postal:import', ['--file' => '~/missing.zip'])
            ->expectsOutputToContain('The file [/home/someone/missing.zip] does not exist')
            ->assertFailed();
    } finally {
        $_SERVER['HOME'] = $originalHome;
    }
});

it('leaves ~user/ and paths that only contain a tilde alone', function (string $path): void {
    $this->artisan('jp-postal:import', ['--file' => $path])
        ->expectsOutputToContain("The file [{$path}] does not exist")
        ->assertFailed();
})->with(['~someone/utf_ken_all.zip', 'data/~/utf_ken_all.zip', '~']);

it('expands a leading ~/ in --url and asks for --file', function (): void {
    seedExistingRow();
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = '/home/someone';

    try {
        $this->artisan('jp-postal:import', ['--url' => '~/utf_ken_all.zip'])
            ->expectsOutputToContain('The URL [/home/someone/utf_ken_all.zip] must start with http:// or https://. Use --file for a local file.')
            ->assertFailed();
    } finally {
        $_SERVER['HOME'] = $originalHome;
    }

    expectOnlyExistingRow();
    Http::assertNothingSent();
});

it('rejects a url that is not http or https', function (string $url): void {
    $this->artisan('jp-postal:import', ['--url' => $url])
        ->expectsOutputToContain('must start with http:// or https://')
        ->assertFailed();

    Http::assertNothingSent();
})->with(['ftp://example.test/data.zip', '/tmp/utf_ken_all.zip', 'utf_ken_all.zip']);

it('fails when the table has not been migrated', function (): void {
    Schema::drop('jp_postal_codes');

    $this->artisan('jp-postal:import', ['--file' => fixturePath()])
        ->expectsOutputToContain('The [jp_postal_codes] table does not exist.')
        ->assertFailed();
});

it('uses the configured table name', function (): void {
    config()->set('jp-postal.drivers.database.table', 'postal_codes');
    migratePostalCodesTable();

    $this->artisan('jp-postal:import', ['--file' => fixturePath()])->assertSuccessful();

    expect(DB::table('postal_codes')->count())->toBe(FIXTURE_ROWS);
});

it('refuses to run in production without confirmation unless forced', function (): void {
    app()['env'] = 'production';

    // Artisan::call() instead of $this->artisan(): the test helper mocks the output differently across
    // Laravel versions, so the confirmation would be asked on some and skipped on others.
    $exitCode = Artisan::call('jp-postal:import', ['--file' => fixturePath(), '--no-interaction' => true]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Command cancelled.')
        ->and(DB::table('jp_postal_codes')->count())->toBe(0);

    $this->artisan('jp-postal:import', ['--file' => fixturePath(), '--force' => true])->assertSuccessful();

    expect(DB::table('jp_postal_codes')->count())->toBe(FIXTURE_ROWS);
});
