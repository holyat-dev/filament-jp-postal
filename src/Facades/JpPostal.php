<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Facades;

use Holyat\FilamentJpPostal\JpPostalManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Support\Collection<int, \Holyat\FilamentJpPostal\Data\Address> lookup(string $zipcode)
 * @method static \Holyat\FilamentJpPostal\Drivers\ManagedDriver driver(\UnitEnum|string|null $driver = null)
 * @method static \Holyat\FilamentJpPostal\JpPostalManager extend(string $driver, \Closure $callback)
 * @method static string getDefaultDriver()
 *
 * @see JpPostalManager
 */
final class JpPostal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return JpPostalManager::class;
    }
}
