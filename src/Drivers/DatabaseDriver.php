<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Drivers;

use Holyat\FilamentJpPostal\Contracts\PostalCodeDriver;
use Holyat\FilamentJpPostal\Data\Address;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Looks up postal codes imported with `php artisan jp-postal:import`.
 */
final class DatabaseDriver implements PostalCodeDriver
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table,
    ) {}

    public function lookup(string $zipcode): Collection
    {
        return $this->connection->table($this->table)
            ->where('zipcode', $zipcode)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): Address => new Address(
                zipcode: $row->zipcode,
                prefectureCode: $row->prefecture_code,
                prefecture: $row->prefecture,
                city: $row->city,
                town: $row->town,
                prefectureKana: $row->prefecture_kana,
                cityKana: $row->city_kana,
                townKana: $row->town_kana,
                localGovernmentCode: $row->local_government_code,
            ))
            // Rows that differed only in a stripped annotation, e.g. 「音江町（１〜２丁目）」 and 「音江町（音江）」.
            ->unique(fn (Address $address): string => serialize($address->toArray()))
            ->values();
    }
}
