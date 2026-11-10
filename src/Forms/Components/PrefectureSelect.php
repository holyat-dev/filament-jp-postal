<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Forms\Components;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;
use Holyat\FilamentJpPostal\Forms\StateCasts\StringOptionStateCast;
use Holyat\FilamentJpPostal\Support\Prefectures;

/**
 * The 47 prefectures in JIS order. The value is the prefecture name, or the
 * two-digit JIS code ('01'–'47') after calling codes().
 */
class PrefectureSelect extends Select
{
    protected bool|Closure $usesCodes = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(static fn (): string => __('filament-jp-postal::messages.prefecture'));
        $this->searchable();
        // The component is injected instead of using $this, which would be the original after Filament clones it.
        $this->options(static fn (PrefectureSelect $component): array => $component->getPrefectureOptions());
    }

    public function codes(bool|Closure $condition = true): static
    {
        $this->usesCodes = $condition;

        return $this;
    }

    public function usesCodes(): bool
    {
        return (bool) $this->evaluate($this->usesCodes);
    }

    /**
     * @return array<string, string> value => prefecture name
     */
    public function getPrefectureOptions(): array
    {
        $names = Prefectures::names();

        // Codes such as '13' become int array keys here; the state cast below keeps the state a string.
        return array_combine($this->usesCodes() ? Prefectures::codes() : $names, $names);
    }

    /**
     * Select casts digit-only state to int by default, which would turn '13' into 13 but leave '01' a string.
     *
     * @return array<StateCast>
     */
    public function getDefaultStateCasts(): array
    {
        if ($this->hasCustomStateCasts() || filled($this->getEnum())) {
            return parent::getDefaultStateCasts();
        }

        return [new StringOptionStateCast(isMultiple: $this->isMultiple())];
    }
}
