<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\Data\Address;
use Holyat\FilamentJpPostal\Drivers\ZipcloudDriver;
use Holyat\FilamentJpPostal\Exceptions\PostalLookupFailedException;
use Holyat\FilamentJpPostal\Facades\JpPostal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const ZIPCLOUD_URL = 'https://zipcloud.ibsnet.co.jp/api/search*';

/**
 * @param  list<array<string, string>>|null  $results
 * @return array<string, mixed>
 */
function zipcloudBody(?array $results, int $status = 200, ?string $message = null): array
{
    return ['message' => $message, 'results' => $results, 'status' => $status];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake();
});

it('is the default driver', function (): void {
    expect(JpPostal::driver()->getDriver())->toBeInstanceOf(ZipcloudDriver::class);
});

it('maps a single result', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::response(zipcloudBody([[
        'address1' => '東京都',
        'address2' => '千代田区',
        'address3' => '千代田',
        'kana1' => 'ﾄｳｷｮｳﾄ',
        'kana2' => 'ﾁﾖﾀﾞｸ',
        'kana3' => 'ﾁﾖﾀﾞ',
        'prefcode' => '13',
        'zipcode' => '1000001',
    ]]))]);

    $addresses = JpPostal::lookup('100-0001');

    expect($addresses)->toHaveCount(1)
        ->and($addresses->first())->toEqual(new Address(
            zipcode: '1000001',
            prefectureCode: '13',
            prefecture: '東京都',
            city: '千代田区',
            town: '千代田',
            prefectureKana: 'トウキョウト',
            cityKana: 'チヨダク',
            townKana: 'チヨダ',
            localGovernmentCode: null,
        ));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://zipcloud.ibsnet.co.jp/api/search?zipcode=1000001');
});

it('converts half-width katakana with voiced marks to full-width', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::response(zipcloudBody([[
        'address1' => '北海道',
        'address2' => '札幌市中央区',
        'address3' => '',
        'kana1' => 'ﾎｯｶｲﾄﾞｳ',
        'kana2' => 'ｻｯﾎﾟﾛｼﾁｭｳｵｳｸ',
        'kana3' => '',
        'prefcode' => '1',
        'zipcode' => '0600000',
    ]]))]);

    $address = JpPostal::lookup('0600000')->first();

    expect($address->prefectureKana)->toBe('ホッカイドウ')
        ->and($address->cityKana)->toBe('サッポロシチュウオウク')
        ->and($address->townKana)->toBeNull()
        ->and($address->town)->toBe('')
        ->and($address->prefectureCode)->toBe('01');
});

it('keeps full-width katakana as it is', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::response(zipcloudBody([[
        'address1' => '東京都', 'address2' => '千代田区', 'address3' => '千代田',
        'kana1' => 'トウキョウト', 'kana2' => 'チヨダク', 'kana3' => 'チヨダ',
        'prefcode' => '13', 'zipcode' => '1000001',
    ]]))]);

    expect(JpPostal::lookup('1000001')->first()->townKana)->toBe('チヨダ');
});

it('maps multiple results', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::response(zipcloudBody([
        ['address1' => '北海道', 'address2' => '美唄市', 'address3' => '上美唄町協和', 'kana1' => 'ﾎｯｶｲﾄﾞｳ', 'kana2' => 'ﾋﾞﾊﾞｲｼ', 'kana3' => 'ｶﾐﾋﾞﾊﾞｲﾁｮｳｷｮｳﾜ', 'prefcode' => '1', 'zipcode' => '0790177'],
        ['address1' => '北海道', 'address2' => '美唄市', 'address3' => '上美唄町南', 'kana1' => 'ﾎｯｶｲﾄﾞｳ', 'kana2' => 'ﾋﾞﾊﾞｲｼ', 'kana3' => 'ｶﾐﾋﾞﾊﾞｲﾁｮｳﾐﾅﾐ', 'prefcode' => '1', 'zipcode' => '0790177'],
    ]))]);

    $addresses = JpPostal::lookup('079-0177');

    expect($addresses)->toHaveCount(2)
        ->and($addresses->pluck('town')->all())->toBe(['上美唄町協和', '上美唄町南'])
        ->and($addresses->keys()->all())->toBe([0, 1]);
});

it('returns an empty collection when nothing matches', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::response(zipcloudBody(null))]);

    expect(JpPostal::lookup('9999999'))->toBeEmpty();
});

it('reports errors returned in the response body', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::response(zipcloudBody(null, 400, 'パラメータ「郵便番号」の桁数が不正です。'))]);

    try {
        JpPostal::lookup('1000001');
        $this->fail('Expected an exception.');
    } catch (PostalLookupFailedException $e) {
        expect($e->reason)->toBe(PostalLookupFailedException::REASON_STATUS)
            ->and($e->status)->toBe(400)
            ->and($e->driver)->toBe('zipcloud')
            ->and($e->isConnectionError())->toBeFalse()
            ->and($e->getMessage())->toContain('桁数が不正');
    }
});

it('retries once on a server error and then fails', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::response('Internal Server Error', 500)]);

    try {
        JpPostal::lookup('1000001');
        $this->fail('Expected an exception.');
    } catch (PostalLookupFailedException $e) {
        expect($e->reason)->toBe(PostalLookupFailedException::REASON_STATUS)
            ->and($e->status)->toBe(500);
    }

    Http::assertSentCount(2);
});

it('recovers when the retry succeeds', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::sequence()
        ->push('Service Unavailable', 503)
        ->push(zipcloudBody([['address1' => '東京都', 'address2' => '千代田区', 'address3' => '千代田', 'kana1' => '', 'kana2' => '', 'kana3' => '', 'prefcode' => '13', 'zipcode' => '1000001']])),
    ]);

    expect(JpPostal::lookup('1000001'))->toHaveCount(1);

    Http::assertSentCount(2);
});

it('does not retry client errors', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::response('Not Found', 404)]);

    expect(fn () => JpPostal::lookup('1000001'))
        ->toThrow(PostalLookupFailedException::class, 'status 404');

    Http::assertSentCount(1);
});

it('retries once on a connection failure such as a timeout and then fails', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::failedConnection('cURL error 28: Operation timed out')]);

    try {
        JpPostal::lookup('1000001');
        $this->fail('Expected an exception.');
    } catch (PostalLookupFailedException $e) {
        expect($e->isConnectionError())->toBeTrue()
            ->and($e->status)->toBeNull()
            ->and($e->getPrevious())->not->toBeNull();
    }

    Http::assertSentCount(2);
});

it('fails on a response that is not zipcloud json', function (mixed $body): void {
    Http::fake([ZIPCLOUD_URL => Http::response($body)]);

    expect(fn () => JpPostal::lookup('1000001'))
        ->toThrow(PostalLookupFailedException::class, 'could not be read');
})->with([
    'html' => ['<html></html>'],
    'missing status' => [['results' => null]],
    'results not a list' => [['status' => 200, 'results' => 'oops']],
]);

it('uses the configured endpoint', function (): void {
    config()->set('jp-postal.drivers.zipcloud.endpoint', 'https://example.test/search');
    Http::fake(['https://example.test/*' => Http::response(zipcloudBody(null))]);

    JpPostal::lookup('1000001');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://example.test/search?zipcode=1000001'));
});

it('caches results so the api is called only once', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::response(zipcloudBody([['address1' => '東京都', 'address2' => '千代田区', 'address3' => '千代田', 'kana1' => '', 'kana2' => '', 'kana3' => '', 'prefcode' => '13', 'zipcode' => '1000001']]))]);

    $first = JpPostal::lookup('100-0001');
    $second = JpPostal::lookup('１０００００１');

    expect($second)->toEqual($first)
        ->and(cache()->has('jp-postal:zipcloud:1000001'))->toBeTrue();

    Http::assertSentCount(1);
});

it('does not cache failures', function (): void {
    Http::fake([ZIPCLOUD_URL => Http::sequence()
        ->push('error', 500)
        ->push('error', 500)
        ->push(zipcloudBody(null)),
    ]);

    expect(fn () => JpPostal::lookup('1000001'))->toThrow(PostalLookupFailedException::class);
    expect(JpPostal::lookup('1000001'))->toBeEmpty();

    Http::assertSentCount(3);
});
