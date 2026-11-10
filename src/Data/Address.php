<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, string|null>
 */
final readonly class Address implements Arrayable
{
    public function __construct(
        public string $zipcode,
        public string $prefectureCode,
        public string $prefecture,
        public string $city,
        public string $town,
        public ?string $prefectureKana = null,
        public ?string $cityKana = null,
        public ?string $townKana = null,
        public ?string $localGovernmentCode = null,
    ) {}

    /**
     * @param  array<string, string|null>  $attributes  As produced by toArray().
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            zipcode: (string) $attributes['zipcode'],
            prefectureCode: (string) $attributes['prefectureCode'],
            prefecture: (string) $attributes['prefecture'],
            city: (string) $attributes['city'],
            town: (string) $attributes['town'],
            prefectureKana: $attributes['prefectureKana'] ?? null,
            cityKana: $attributes['cityKana'] ?? null,
            townKana: $attributes['townKana'] ?? null,
            localGovernmentCode: $attributes['localGovernmentCode'] ?? null,
        );
    }

    public function fullAddress(): string
    {
        return $this->prefecture.$this->city.$this->town;
    }

    /**
     * @return array{zipcode: string, prefectureCode: string, prefecture: string, city: string, town: string, prefectureKana: string|null, cityKana: string|null, townKana: string|null, localGovernmentCode: string|null}
     */
    public function toArray(): array
    {
        return [
            'zipcode' => $this->zipcode,
            'prefectureCode' => $this->prefectureCode,
            'prefecture' => $this->prefecture,
            'city' => $this->city,
            'town' => $this->town,
            'prefectureKana' => $this->prefectureKana,
            'cityKana' => $this->cityKana,
            'townKana' => $this->townKana,
            'localGovernmentCode' => $this->localGovernmentCode,
        ];
    }
}
