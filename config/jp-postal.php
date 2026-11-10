<?php

declare(strict_types=1);

return [
    'default' => env('JP_POSTAL_DRIVER', 'zipcloud'),

    'drivers' => [
        'zipcloud' => [
            'endpoint' => 'https://zipcloud.ibsnet.co.jp/api/search',
            'timeout' => 5,
        ],
        'database' => [
            'connection' => null,
            'table' => 'jp_postal_codes',
            'source_url' => 'https://www.post.japanpost.jp/service/search/zipcode/download/utf/zip/utf_ken_all.zip',
        ],
        // v1.1: 'japanpost' => [...]
    ],

    'cache' => [
        'store' => null,
        'ttl' => 60 * 60 * 24 * 30,
    ],
];
