<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Support;

use Holyat\FilamentJpPostal\Exceptions\InvalidPostalCodeException;

final class PostalCodeNormalizer
{
    /**
     * Postal mark, whitespace and the hyphen-like characters people type between the two parts.
     */
    private const IGNORED_CHARACTERS = '/[\s\x{3000}〒\-\x{2010}-\x{2015}\x{2212}\x{FF0D}\x{30FC}\x{FF70}]/u';

    /**
     * @throws InvalidPostalCodeException
     */
    public static function normalize(string $value): string
    {
        return self::tryNormalize($value) ?? throw InvalidPostalCodeException::make($value);
    }

    public static function tryNormalize(string $value): ?string
    {
        $normalized = (string) preg_replace(self::IGNORED_CHARACTERS, '', mb_convert_kana($value, 'n'));

        return preg_match('/\A\d{7}\z/', $normalized) === 1 ? $normalized : null;
    }

    public static function format(string $zipcode): string
    {
        return substr($zipcode, 0, 3).'-'.substr($zipcode, 3);
    }
}
