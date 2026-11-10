<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\FilamentJpPostalServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getProvider(FilamentJpPostalServiceProvider::class))
        ->toBeInstanceOf(FilamentJpPostalServiceProvider::class);
});

it('merges the package config', function (): void {
    expect(config('jp-postal.default'))->toBe('zipcloud')
        ->and(config('jp-postal.drivers.zipcloud.endpoint'))->toBe('https://zipcloud.ibsnet.co.jp/api/search')
        ->and(config('jp-postal.drivers.zipcloud.timeout'))->toBe(5)
        ->and(config('jp-postal.drivers.database.table'))->toBe('jp_postal_codes')
        ->and(config('jp-postal.cache.store'))->toBeNull()
        ->and(config('jp-postal.cache.ttl'))->toBe(60 * 60 * 24 * 30);
});
