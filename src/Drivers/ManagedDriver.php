<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Drivers;

use Holyat\FilamentJpPostal\Contracts\PostalCodeDriver;
use Holyat\FilamentJpPostal\Contracts\ShouldCacheLookups;
use Holyat\FilamentJpPostal\Data\Address;
use Holyat\FilamentJpPostal\Support\PostalCodeNormalizer;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;

/**
 * A driver as handed out by the manager: normalizes the input and caches results.
 */
final class ManagedDriver implements PostalCodeDriver
{
    public const EMPTY_RESULT_TTL = 60 * 60 * 24;

    public function __construct(
        private readonly string $name,
        private readonly PostalCodeDriver $driver,
        private readonly Repository $cache,
        private readonly int $ttl,
    ) {}

    public function lookup(string $zipcode): Collection
    {
        $zipcode = PostalCodeNormalizer::normalize($zipcode);

        if (! $this->driver instanceof ShouldCacheLookups) {
            return $this->driver->lookup($zipcode);
        }

        $key = $this->cacheKey($zipcode);

        /** @var list<array<string, string|null>>|null $cached */
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return collect($cached)->map(Address::fromArray(...))->values();
        }

        $addresses = $this->driver->lookup($zipcode)->values();

        // Addresses are cached as arrays: Laravel's cache may refuse to unserialize package classes.
        $this->cache->put(
            $key,
            $addresses->map(fn (Address $address): array => $address->toArray())->all(),
            $addresses->isEmpty() ? min(self::EMPTY_RESULT_TTL, $this->ttl) : $this->ttl,
        );

        return $addresses;
    }

    public function cacheKey(string $zipcode): string
    {
        return "jp-postal:{$this->name}:{$zipcode}";
    }

    public function getDriver(): PostalCodeDriver
    {
        return $this->driver;
    }
}
