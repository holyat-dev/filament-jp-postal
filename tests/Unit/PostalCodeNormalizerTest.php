<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\Exceptions\InvalidPostalCodeException;
use Holyat\FilamentJpPostal\Support\PostalCodeNormalizer;

it('normalizes postal codes', function (string $input): void {
    expect(PostalCodeNormalizer::normalize($input))->toBe('1000001');
})->with([
    'plain' => '1000001',
    'hyphen' => '100-0001',
    'full-width digits and hyphen' => '１００－０００１',
    'postal mark' => '〒1000001',
    'postal mark with space and hyphen' => '〒 100-0001',
    'full-width space' => '100　0001',
    'surrounding whitespace' => " 100-0001\n",
    'prolonged sound mark' => '100ー0001',
    'minus sign' => '100−0001',
    'half-width prolonged sound mark' => '100ｰ0001',
]);

it('rejects invalid postal codes', function (string $input): void {
    PostalCodeNormalizer::normalize($input);
})->throws(InvalidPostalCodeException::class)->with([
    'empty' => '',
    'too short' => '100-001',
    'too long' => '100-00011',
    'letters' => 'abc-defg',
    'mixed' => '100-000a',
    'digital address' => 'A1B2C3D',
]);

it('returns null instead of throwing from tryNormalize', function (): void {
    expect(PostalCodeNormalizer::tryNormalize('１００－０００１'))->toBe('1000001')
        ->and(PostalCodeNormalizer::tryNormalize('100-00'))->toBeNull();
});

it('includes the invalid value in the exception message', function (): void {
    expect(fn () => PostalCodeNormalizer::normalize('12-34'))
        ->toThrow(InvalidPostalCodeException::class, '12-34');
});

it('formats a normalized postal code with a hyphen', function (): void {
    expect(PostalCodeNormalizer::format('1000001'))->toBe('100-0001');
});
