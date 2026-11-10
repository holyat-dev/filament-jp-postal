<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Support;

final class Prefectures
{
    /**
     * Prefecture names in JIS X 0401 order; the code is the 1-based position, zero-padded to two digits.
     *
     * A code => name map is intentionally avoided: PHP casts numeric string keys such as '13' to int.
     */
    private const NAMES = [
        '北海道',
        '青森県',
        '岩手県',
        '宮城県',
        '秋田県',
        '山形県',
        '福島県',
        '茨城県',
        '栃木県',
        '群馬県',
        '埼玉県',
        '千葉県',
        '東京都',
        '神奈川県',
        '新潟県',
        '富山県',
        '石川県',
        '福井県',
        '山梨県',
        '長野県',
        '岐阜県',
        '静岡県',
        '愛知県',
        '三重県',
        '滋賀県',
        '京都府',
        '大阪府',
        '兵庫県',
        '奈良県',
        '和歌山県',
        '鳥取県',
        '島根県',
        '岡山県',
        '広島県',
        '山口県',
        '徳島県',
        '香川県',
        '愛媛県',
        '高知県',
        '福岡県',
        '佐賀県',
        '長崎県',
        '熊本県',
        '大分県',
        '宮崎県',
        '鹿児島県',
        '沖縄県',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * @return list<string> '01' to '47'
     */
    public static function codes(): array
    {
        return array_map(self::formatCode(...), array_keys(self::NAMES));
    }

    public static function name(string $code): ?string
    {
        if (preg_match('/\A\d{1,2}\z/', $code) !== 1) {
            return null;
        }

        return self::NAMES[(int) $code - 1] ?? null;
    }

    public static function code(string $name): ?string
    {
        $index = array_search($name, self::NAMES, true);

        return $index === false ? null : self::formatCode($index);
    }

    private static function formatCode(int $index): string
    {
        return sprintf('%02d', $index + 1);
    }
}
