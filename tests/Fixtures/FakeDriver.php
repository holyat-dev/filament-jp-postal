<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Tests\Fixtures;

use Holyat\FilamentJpPostal\Contracts\PostalCodeDriver;
use Holyat\FilamentJpPostal\Data\Address;
use Illuminate\Support\Collection;

class FakeDriver implements PostalCodeDriver
{
    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param  array<string, list<Address>>  $results
     */
    public function __construct(private readonly array $results = []) {}

    public function lookup(string $zipcode): Collection
    {
        $this->lookups[] = $zipcode;

        return collect($this->results[$zipcode] ?? []);
    }
}
