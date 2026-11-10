<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Exceptions;

use InvalidArgumentException;

final class InvalidPostalCodeException extends InvalidArgumentException
{
    public static function make(string $value): self
    {
        return new self(sprintf('The value [%s] is not a valid Japanese postal code.', $value));
    }
}
