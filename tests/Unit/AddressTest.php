<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\Data\Address;

it('builds the full address', function (): void {
    $address = new Address(
        zipcode: '1000001',
        prefectureCode: '13',
        prefecture: '東京都',
        city: '千代田区',
        town: '千代田',
    );

    expect($address->fullAddress())->toBe('東京都千代田区千代田');
});

it('defaults optional values to null', function (): void {
    $address = new Address('1000001', '13', '東京都', '千代田区', '千代田');

    expect($address->prefectureKana)->toBeNull()
        ->and($address->cityKana)->toBeNull()
        ->and($address->townKana)->toBeNull()
        ->and($address->localGovernmentCode)->toBeNull();
});

it('converts to an array', function (): void {
    $address = new Address(
        zipcode: '1000001',
        prefectureCode: '13',
        prefecture: '東京都',
        city: '千代田区',
        town: '千代田',
        prefectureKana: 'トウキョウト',
        cityKana: 'チヨダク',
        townKana: 'チヨダ',
        localGovernmentCode: '13101',
    );

    expect($address->toArray())->toBe([
        'zipcode' => '1000001',
        'prefectureCode' => '13',
        'prefecture' => '東京都',
        'city' => '千代田区',
        'town' => '千代田',
        'prefectureKana' => 'トウキョウト',
        'cityKana' => 'チヨダク',
        'townKana' => 'チヨダ',
        'localGovernmentCode' => '13101',
    ]);
});

it('is immutable', function (): void {
    $address = new Address('1000001', '13', '東京都', '千代田区', '千代田');

    expect(fn () => $address->town = '丸の内')->toThrow(Error::class);
});

it('round-trips through an array', function (): void {
    $address = new Address('1000001', '13', '東京都', '千代田区', '千代田', localGovernmentCode: '13101');

    expect(Address::fromArray($address->toArray()))->toEqual($address);
});
