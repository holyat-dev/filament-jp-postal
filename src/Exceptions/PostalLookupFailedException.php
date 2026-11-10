<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Exceptions;

use RuntimeException;
use Throwable;

final class PostalLookupFailedException extends RuntimeException
{
    public const REASON_CONNECTION = 'connection';

    public const REASON_STATUS = 'status';

    public const REASON_RESPONSE = 'response';

    private function __construct(
        string $message,
        public readonly string $driver,
        public readonly string $reason,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connectionFailed(string $driver, Throwable $previous): self
    {
        return new self(
            sprintf('[%s] Could not connect to the postal code service: %s', $driver, $previous->getMessage()),
            $driver,
            self::REASON_CONNECTION,
            previous: $previous,
        );
    }

    public static function unexpectedStatus(string $driver, int $status, ?string $message = null): self
    {
        return new self(
            sprintf('[%s] The postal code service responded with status %d%s', $driver, $status, $message === null ? '.' : ": {$message}"),
            $driver,
            self::REASON_STATUS,
            $status,
        );
    }

    public static function invalidResponse(string $driver): self
    {
        return new self(
            sprintf('[%s] The postal code service returned a response that could not be read.', $driver),
            $driver,
            self::REASON_RESPONSE,
        );
    }

    public function isConnectionError(): bool
    {
        return $this->reason === self::REASON_CONNECTION;
    }
}
