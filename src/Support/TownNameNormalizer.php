<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Support;

/**
 * Cleans the 町域 column of Japan Post's utf_ken_all.csv, which mixes real town
 * names with annotations that must not end up in a user's address.
 *
 * Rules, applied in order (fixed against utf_ken_all.csv updated 2026-10, 124,527 rows):
 *  1. 「以下に掲載がない場合」 becomes empty.
 *  2. 「○○の次に…がくる場合」 becomes empty, even when followed by an annotation.
 *  3. 「○○一円」 becomes empty, but 「一円」 alone is a real town name (滋賀県犬上郡多賀町一円).
 *  4. Everything from the first 「（」 to the last 「）」 is removed.
 *  5. A range or list of 地割 (「越中畑６４地割〜越中畑６６地割」) is cut before its first digit or 「第」.
 *     A single 地割 (「芦ケ沢７２地割」) is kept.
 *  6. A list of several towns that is left after the rules above (「井道、奥井道、内井道」, 「甲、乙」)
 *     becomes empty, because the row does not say which one the address is in.
 */
final class TownNameNormalizer
{
    private const NOT_LISTED = '以下に掲載がない場合';

    private const ADDRESS_NUMBER_FOLLOWS = '/の次に.+?がくる場合/u';

    private const WHOLE_AREA = '一円';

    private const ANNOTATION = '/（.*）/u';

    private const CHIWARI_START = '/[0-9０-９第]/u';

    public static function normalize(string $town): string
    {
        $town = trim($town);

        if (self::isNotATownName($town)) {
            return '';
        }

        $town = self::stripAnnotation($town);

        if (self::isChiwariRange($town)) {
            $town = self::cutBefore($town, self::CHIWARI_START) ?? $town;
        }

        return str_contains($town, '、') ? '' : trim($town);
    }

    /**
     * Applies the same rules to the 町域カナ column and returns full-width katakana.
     *
     * Returns null when the town name becomes empty, or when a 地割 range cannot be
     * cut at the matching position in the kana.
     */
    public static function normalizeKana(string $kana, string $town): ?string
    {
        $normalizedTown = self::normalize($town);

        if ($normalizedTown === '') {
            return null;
        }

        $kana = self::stripAnnotation(mb_convert_kana(trim($kana), 'KV'));
        $strippedTown = self::stripAnnotation(trim($town));

        if (self::isChiwariRange($strippedTown)) {
            // The kana spells 「第」 as 「ダイ」 and keeps the digits, e.g. 「タネイチダイ１５チワリ−ダイ２１チワリ」.
            $cutAtDai = mb_substr($strippedTown, mb_strlen($normalizedTown), 1) === '第';
            $kana = self::cutBefore($kana, $cutAtDai ? '/ダイ[0-9０-９]/u' : '/[0-9０-９]/u');
        }

        $kana = $kana === null ? '' : trim($kana);

        return $kana === '' ? null : $kana;
    }

    private static function isNotATownName(string $town): bool
    {
        return $town === self::NOT_LISTED
            || preg_match(self::ADDRESS_NUMBER_FOLLOWS, $town) === 1
            || (str_ends_with($town, self::WHOLE_AREA) && mb_strlen($town) >= 3);
    }

    private static function stripAnnotation(string $value): string
    {
        return (string) preg_replace(self::ANNOTATION, '', $value);
    }

    private static function isChiwariRange(string $town): bool
    {
        return str_contains($town, '地割') && (str_contains($town, '〜') || str_contains($town, '、'));
    }

    /**
     * Returns the part before the first match, or null when the pattern does not match.
     */
    private static function cutBefore(string $value, string $pattern): ?string
    {
        if (preg_match($pattern, $value, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr($value, 0, $match[0][1]);
    }
}
