<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Drivers;

use Holyat\FilamentJpPostal\Contracts\PostalCodeDriver;
use Holyat\FilamentJpPostal\Contracts\ShouldCacheLookups;
use Holyat\FilamentJpPostal\Data\Address;
use Holyat\FilamentJpPostal\Exceptions\PostalLookupFailedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * @see https://zipcloud.ibsnet.co.jp/doc/api
 */
final class ZipcloudDriver implements PostalCodeDriver, ShouldCacheLookups
{
    public const NAME = 'zipcloud';

    private const RETRY_DELAY_MILLISECONDS = 200;

    public function __construct(
        private readonly Factory $http,
        private readonly string $endpoint,
        private readonly int|float $timeout,
    ) {}

    public function lookup(string $zipcode): Collection
    {
        try {
            $response = $this->http
                ->acceptJson()
                ->timeout($this->timeout)
                ->retry(2, self::RETRY_DELAY_MILLISECONDS, $this->shouldRetry(...), throw: false)
                ->get($this->endpoint, ['zipcode' => $zipcode]);
        } catch (ConnectionException $e) {
            throw PostalLookupFailedException::connectionFailed(self::NAME, $e);
        }

        if (! $response->successful()) {
            throw PostalLookupFailedException::unexpectedStatus(self::NAME, $response->status());
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['status'])) {
            throw PostalLookupFailedException::invalidResponse(self::NAME);
        }

        // zipcloud reports its own errors (e.g. 400 for a bad parameter) in the body.
        if ((int) $body['status'] !== 200) {
            throw PostalLookupFailedException::unexpectedStatus(
                self::NAME,
                (int) $body['status'],
                is_string($body['message'] ?? null) ? $body['message'] : null,
            );
        }

        $results = $body['results'] ?? null;

        if ($results === null) {
            return collect();
        }

        if (! is_array($results)) {
            throw PostalLookupFailedException::invalidResponse(self::NAME);
        }

        return collect(array_values($results))->map(fn (mixed $result): Address => $this->toAddress($result, $zipcode));
    }

    private function shouldRetry(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && $e->response->serverError());
    }

    private function toAddress(mixed $result, string $zipcode): Address
    {
        if (! is_array($result)) {
            throw PostalLookupFailedException::invalidResponse(self::NAME);
        }

        return new Address(
            zipcode: $this->string($result['zipcode'] ?? null) ?? $zipcode,
            prefectureCode: str_pad($this->string($result['prefcode'] ?? null) ?? '', 2, '0', STR_PAD_LEFT),
            prefecture: $this->string($result['address1'] ?? null) ?? '',
            city: $this->string($result['address2'] ?? null) ?? '',
            town: $this->string($result['address3'] ?? null) ?? '',
            prefectureKana: $this->kana($result['kana1'] ?? null),
            cityKana: $this->kana($result['kana2'] ?? null),
            townKana: $this->kana($result['kana3'] ?? null),
        );
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * zipcloud may return half-width katakana; the package always exposes full-width.
     */
    private function kana(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value === null || $value === '' ? null : mb_convert_kana($value, 'KV');
    }
}
