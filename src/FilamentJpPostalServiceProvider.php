<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal;

use Holyat\FilamentJpPostal\Commands\ImportPostalCodesCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class FilamentJpPostalServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-jp-postal';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile('jp-postal')
            ->hasTranslations()
            ->hasMigration('create_jp_postal_codes_table')
            ->hasCommand(ImportPostalCodesCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(JpPostalManager::class);
    }
}
