<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\Data\Address;
use Holyat\FilamentJpPostal\Drivers\ManagedDriver;
use Holyat\FilamentJpPostal\Exceptions\InvalidPostalCodeException;
use Holyat\FilamentJpPostal\Facades\JpPostal;
use Holyat\FilamentJpPostal\JpPostalManager;
use Holyat\FilamentJpPostal\Tests\Fixtures\CachingFakeDriver;
use Holyat\FilamentJpPostal\Tests\Fixtures\FakeDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

function tokyo(): Address
{
    return new Address('1000001', '13', '東京都', '千代田区', '千代田', 'トウキョウト', 'チヨダク', 'チヨダ', '13101');
}

beforeEach(function (): void {
    // extend() rebinds the closure to the manager, so the drivers are passed as local variables.
    $fake = $this->fake = new FakeDriver(['1000001' => [tokyo()]]);
    $cachingFake = $this->cachingFake = new CachingFakeDriver(['1000001' => [tokyo()]]);

    JpPostal::extend('fake', fn () => $fake);
    JpPostal::extend('caching', fn () => $cachingFake);
});

it('resolves the manager as a singleton behind the facade', function (): void {
    expect(app(JpPostalManager::class))->toBe(app(JpPostalManager::class))
        ->and(JpPostal::getFacadeRoot())->toBe(app(JpPostalManager::class));
});

it('uses the configured default driver', function (): void {
    expect(JpPostal::getDefaultDriver())->toBe('zipcloud');

    config()->set('jp-postal.default', 'fake');

    expect(JpPostal::lookup('100-0001'))->toHaveCount(1)
        ->and($this->fake->lookups)->toBe(['1000001']);
});

it('wraps drivers registered through extend', function (): void {
    $driver = JpPostal::driver('fake');

    expect($driver)->toBeInstanceOf(ManagedDriver::class)
        ->and($driver->getDriver())->toBe($this->fake)
        ->and(JpPostal::driver('fake'))->toBe($driver);
});

it('normalizes the postal code before it reaches the driver', function (): void {
    $addresses = JpPostal::driver('fake')->lookup('〒１００－０００１');

    expect($this->fake->lookups)->toBe(['1000001'])
        ->and($addresses->first())->toEqual(tokyo());
});

it('rejects invalid postal codes without calling the driver', function (): void {
    expect(fn () => JpPostal::driver('fake')->lookup('100-000'))
        ->toThrow(InvalidPostalCodeException::class);

    expect($this->fake->lookups)->toBe([]);
});

it('returns an empty collection when nothing matches', function (): void {
    expect(JpPostal::driver('fake')->lookup('9999999'))->toBeEmpty();
});

it('does not cache drivers that do not ask for it', function (): void {
    JpPostal::driver('fake')->lookup('1000001');
    JpPostal::driver('fake')->lookup('1000001');

    expect($this->fake->lookups)->toHaveCount(2)
        ->and(Cache::has('jp-postal:fake:1000001'))->toBeFalse();
});

it('caches results of cacheable drivers', function (): void {
    $first = JpPostal::driver('caching')->lookup('100-0001');
    $second = JpPostal::driver('caching')->lookup('1000001');

    expect($this->cachingFake->lookups)->toBe(['1000001'])
        ->and($second)->toEqual($first)
        ->and($second->first())->toBeInstanceOf(Address::class)
        ->and(Cache::get('jp-postal:caching:1000001'))->toBe([tokyo()->toArray()]);
});

it('expires cached results after the configured ttl', function (): void {
    config()->set('jp-postal.cache.ttl', 3600);

    JpPostal::driver('caching')->lookup('1000001');

    $this->travel(3599)->seconds();
    JpPostal::driver('caching')->lookup('1000001');
    expect($this->cachingFake->lookups)->toHaveCount(1);

    $this->travel(2)->seconds();
    JpPostal::driver('caching')->lookup('1000001');
    expect($this->cachingFake->lookups)->toHaveCount(2);
});

it('caches empty results for one day only', function (): void {
    JpPostal::driver('caching')->lookup('9999999');

    $this->travel(ManagedDriver::EMPTY_RESULT_TTL - 1)->seconds();
    JpPostal::driver('caching')->lookup('9999999');
    expect($this->cachingFake->lookups)->toHaveCount(1);

    $this->travel(2)->seconds();
    JpPostal::driver('caching')->lookup('9999999');
    expect($this->cachingFake->lookups)->toHaveCount(2);
});

it('does not cache failed lookups', function (): void {
    JpPostal::extend('failing', fn () => new class extends CachingFakeDriver
    {
        public function lookup(string $zipcode): Collection
        {
            throw new RuntimeException('down');
        }
    });

    expect(fn () => JpPostal::driver('failing')->lookup('1000001'))->toThrow(RuntimeException::class);
    expect(Cache::has('jp-postal:failing:1000001'))->toBeFalse();
});

it('uses the configured cache store', function (): void {
    config()->set('cache.stores.jp-postal-test', ['driver' => 'array']);
    config()->set('jp-postal.cache.store', 'jp-postal-test');

    JpPostal::driver('caching')->lookup('1000001');

    expect(Cache::store('jp-postal-test')->has('jp-postal:caching:1000001'))->toBeTrue()
        ->and(Cache::store()->has('jp-postal:caching:1000001'))->toBeFalse();
});

it('rejects custom drivers that do not implement the contract', function (): void {
    JpPostal::extend('invalid', fn () => new stdClass);

    JpPostal::driver('invalid');
})->throws(InvalidArgumentException::class, 'must implement');

it('rejects unknown drivers', function (): void {
    JpPostal::driver('unknown');
})->throws(InvalidArgumentException::class, 'Driver [unknown] not supported.');
