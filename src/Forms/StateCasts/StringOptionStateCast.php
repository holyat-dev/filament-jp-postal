<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Forms\StateCasts;

use BackedEnum;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;
use Stringable;

/**
 * Keeps option values as strings. Filament's own option casts turn digit-only values such as '13'
 * into integers, and only accept a flag to stop that from Filament 5.11.
 */
final class StringOptionStateCast implements StateCast
{
    public function __construct(
        private readonly bool $isMultiple = false,
    ) {}

    /**
     * @return string|list<string>|null
     */
    public function get(mixed $state): string|array|null
    {
        return $this->cast($state);
    }

    /**
     * @return string|list<string>|null
     */
    public function set(mixed $state): string|array|null
    {
        return $this->cast($state);
    }

    /**
     * @return string|list<string>|null
     */
    private function cast(mixed $state): string|array|null
    {
        if (! $this->isMultiple) {
            return $this->toString($state);
        }

        if (! is_array($state)) {
            return [];
        }

        return array_values(array_filter(array_map($this->toString(...), $state), fn (?string $value): bool => $value !== null));
    }

    private function toString(mixed $state): ?string
    {
        if ($state instanceof BackedEnum) {
            $state = $state->value;
        }

        // Non-scalar values can only come from a tampered request; treat them as no selection.
        if (blank($state) || (! is_scalar($state) && ! $state instanceof Stringable)) {
            return null;
        }

        return (string) $state;
    }
}
