<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal;

use Holyat\FilamentJpPostal\Contracts\PostalCodeDriver;
use Holyat\FilamentJpPostal\Data\Address;
use Holyat\FilamentJpPostal\Drivers\DatabaseDriver;
use Holyat\FilamentJpPostal\Drivers\ManagedDriver;
use Holyat\FilamentJpPostal\Drivers\ZipcloudDriver;
use Holyat\FilamentJpPostal\Exceptions\InvalidPostalCodeException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * @method ManagedDriver driver(\UnitEnum|string|null $driver = null)
 */
class JpPostalManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('jp-postal.default', 'zipcloud');
    }

    /**
     * @return Collection<int, Address>
     *
     * @throws InvalidPostalCodeException
     */
    public function lookup(string $zipcode): Collection
    {
        return $this->driver()->lookup($zipcode);
    }

    public function createZipcloudDriver(): ZipcloudDriver
    {
        return new ZipcloudDriver(
            http: $this->container->make(HttpFactory::class),
            endpoint: $this->config->get('jp-postal.drivers.zipcloud.endpoint'),
            timeout: $this->config->get('jp-postal.drivers.zipcloud.timeout', 5),
        );
    }

    public function createDatabaseDriver(): DatabaseDriver
    {
        /** @var DatabaseManager $db */
        $db = $this->container->make('db');

        return new DatabaseDriver(
            connection: $db->connection($this->config->get('jp-postal.drivers.database.connection')),
            table: $this->config->get('jp-postal.drivers.database.table', 'jp_postal_codes'),
        );
    }

    /**
     * Wraps every driver, including ones registered through extend(), so that
     * input normalization and caching behave the same for all of them.
     *
     * @param  string  $driver
     */
    protected function createDriver($driver): ManagedDriver
    {
        $instance = parent::createDriver($driver);

        if (! $instance instanceof PostalCodeDriver) {
            throw new InvalidArgumentException(sprintf(
                'Driver [%s] must implement [%s].', $driver, PostalCodeDriver::class,
            ));
        }

        /** @var CacheFactory $cache */
        $cache = $this->container->make('cache');

        return new ManagedDriver(
            name: $driver,
            driver: $instance,
            cache: $cache->store($this->config->get('jp-postal.cache.store')),
            ttl: (int) $this->config->get('jp-postal.cache.ttl', 60 * 60 * 24 * 30),
        );
    }
}
