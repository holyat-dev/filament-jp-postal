<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Contracts;

use Holyat\FilamentJpPostal\Data\Address;
use Illuminate\Support\Collection;

interface PostalCodeDriver
{
    /**
     * Receives a normalized 7-digit postal code without a hyphen.
     *
     * @return Collection<int, Address> An empty collection when nothing matches.
     */
    public function lookup(string $zipcode): Collection;
}
