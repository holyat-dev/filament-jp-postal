<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\Data\Address;
use Holyat\FilamentJpPostal\Drivers\DatabaseDriver;
use Holyat\FilamentJpPostal\Facades\JpPostal;
use Illuminate\Support\Facades\Cache;

pest()->group('database');

beforeEach(function (): void {
    migratePostalCodesTable();
    $this->artisan('jp-postal:import', ['--file' => fixturePath()])->assertSuccessful();
});

it('is available as the database driver', function (): void {
    expect(JpPostal::driver('database')->getDriver())->toBeInstanceOf(DatabaseDriver::class);
});

it('looks up a single address', function (): void {
    expect(JpPostal::driver('database')->lookup('100-0001')->all())->toEqual([
        new Address(
            zipcode: '1000001',
            prefectureCode: '13',
            prefecture: '東京都',
            city: '千代田区',
            town: '千代田',
            prefectureKana: 'トウキョウト',
            cityKana: 'チヨダク',
            townKana: 'チヨダ',
            localGovernmentCode: '13101',
        ),
    ]);
});

it('returns every town for a postal code with several towns', function (): void {
    expect(JpPostal::driver('database')->lookup('0295523')->pluck('town')->all())
        ->toBe(['越中畑', '白木野６７地割', '芦ケ沢７２地割']);
});

it('merges rows that only differed in a stripped annotation', function (): void {
    $addresses = JpPostal::driver('database')->lookup('0741273');

    expect($addresses)->toHaveCount(1)
        ->and($addresses->first()->town)->toBe('音江町')
        ->and($addresses->keys()->all())->toBe([0]);
});

it('returns an empty town for annotation-only rows', function (): void {
    expect(JpPostal::driver('database')->lookup('0600000')->first())
        ->town->toBe('')
        ->townKana->toBeNull()
        ->fullAddress()->toBe('北海道札幌市中央区');
});

it('returns an empty collection when nothing matches', function (): void {
    expect(JpPostal::driver('database')->lookup('9999999'))->toBeEmpty();
});

it('does not cache lookups so a fresh import is visible immediately', function (): void {
    JpPostal::driver('database')->lookup('1000001');

    expect(Cache::has('jp-postal:database:1000001'))->toBeFalse();
});

it('can be the default driver', function (): void {
    config()->set('jp-postal.default', 'database');

    expect(JpPostal::lookup('1000001'))->toHaveCount(1);
});
