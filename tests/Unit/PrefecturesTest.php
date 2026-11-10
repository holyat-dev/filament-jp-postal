<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\Support\Prefectures;

it('contains 47 unique prefectures', function (): void {
    expect(Prefectures::names())->toHaveCount(47)
        ->and(array_unique(Prefectures::names()))->toHaveCount(47);
});

it('provides string codes 01 to 47', function (): void {
    $expected = array_map(fn (int $i): string => sprintf('%02d', $i), range(1, 47));

    expect(Prefectures::codes())->toBe($expected);
});

it('is in JIS order', function (): void {
    expect(Prefectures::name('01'))->toBe('北海道')
        ->and(Prefectures::name('13'))->toBe('東京都')
        ->and(Prefectures::name('14'))->toBe('神奈川県')
        ->and(Prefectures::name('26'))->toBe('京都府')
        ->and(Prefectures::name('27'))->toBe('大阪府')
        ->and(Prefectures::name('47'))->toBe('沖縄県');
});

it('looks up a name by code', function (string $code, ?string $expected): void {
    expect(Prefectures::name($code))->toBe($expected);
})->with([
    'padded' => ['01', '北海道'],
    'unpadded' => ['1', '北海道'],
    'zero' => ['0', null],
    'out of range' => ['48', null],
    'not numeric' => ['ab', null],
    'empty' => ['', null],
]);

it('looks up a string code by name', function (): void {
    expect(Prefectures::code('北海道'))->toBe('01')
        ->and(Prefectures::code('大阪府'))->toBe('27')
        ->and(Prefectures::code('東京'))->toBeNull();
});
