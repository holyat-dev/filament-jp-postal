<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Holyat\FilamentJpPostal\Data\Address;
use Holyat\FilamentJpPostal\Exceptions\PostalLookupFailedException;
use Holyat\FilamentJpPostal\Facades\JpPostal;
use Holyat\FilamentJpPostal\Support\PostalCodeNormalizer;
use Illuminate\Support\Collection;

/**
 * A postal code field that fills the address fields next to it.
 *
 * The field always shows 100-0001. It stores 1000001 by default, or 100-0001 after storeWithHyphen().
 */
class PostalCodeInput extends TextInput
{
    public const LOOKUP_ACTION = 'lookupAddress';

    /**
     * Rewrites the input on the client: full-width digits become half-width, everything but digits is
     * dropped (including hyphen-like characters such as －, ー, −, ‐) and the value is shown as 000-0000.
     *
     * mask() is not used because it rejects full-width digits typed through a Japanese IME, and the
     * value is never touched while the IME is composing. Rendered unescaped in an attribute, so no double quotes.
     */
    private const FORMAT_INPUT_JS = <<<'JS'
        $el.jpPostalFormat = () => {
            const toDigits = (value) => value
                .replace(/[０-９]/g, (character) => String.fromCharCode(character.charCodeAt(0) - 0xFEE0))
                .replace(/[^0-9]/g, '');
            const raw = $el.value;
            const digits = toDigits(raw).slice(0, 7);
            const formatted = digits.length > 3 ? digits.slice(0, 3) + '-' + digits.slice(3) : digits;

            if (formatted === raw) {
                return;
            }

            const digitsBeforeCaret = Math.min(toDigits(raw.slice(0, $el.selectionStart ?? raw.length)).length, 7);
            const caret = digitsBeforeCaret + (digitsBeforeCaret > 3 ? 1 : 0);

            $el.value = formatted;
            $el.setSelectionRange(caret, caret);
            $el.dispatchEvent(new Event('input', { bubbles: true }));
        }
        JS;

    /**
     * Suffix of the sibling state key that records what the last lookup filled. No component owns it,
     * so it lives in the Livewire form data only and is left out of getState().
     */
    public const FILLED_FIELDS_STATE_SUFFIX = '__jp_postal_filled';

    protected bool|Closure $isAutoLookupEnabled = true;

    protected bool|Closure $isLookupActionEnabled = true;

    protected bool|Closure $shouldStoreWithHyphen = false;

    protected bool|Closure $shouldOverwrite = true;

    protected string|Closure|null $driver = null;

    protected ?Closure $afterStateHydratedUsing = null;

    /**
     * Target field paths, relative to this field's container (so they work inside a Repeater).
     *
     * @var array{prefecture: ?string, prefectureCode: ?string, city: ?string, town: ?string, cityTown: ?string}
     */
    protected array $addressFields = [
        'prefecture' => null,
        'prefectureCode' => null,
        'city' => null,
        'town' => null,
        'cityTown' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(static fn (): string => __('filament-jp-postal::messages.postal_code'));
        $this->placeholder('100-0001');
        $this->autocomplete('postal-code');
        $this->inputMode('numeric');
        $this->extraAlpineAttributes([
            'x-init' => self::FORMAT_INPUT_JS,
            'x-on:input' => '$event.isComposing || $el.jpPostalFormat()',
            'x-on:compositionend' => '$el.jpPostalFormat()',
        ], merge: true);

        // Closures take the component as a parameter instead of using $this: Filament clones components
        // (e.g. once per Repeater item) without rebinding closures, so $this would be the original.
        $this->live(debounce: 400, condition: static fn (PostalCodeInput $component): bool => $component->isAutoLookupEnabled());

        parent::afterStateHydrated(static function (PostalCodeInput $component, mixed $state): void {
            $component->state($component->toDisplayFormat($state));

            if ($component->afterStateHydratedUsing !== null) {
                $component->evaluate($component->afterStateHydratedUsing);
            }
        });

        $this->afterStateUpdated(static function (PostalCodeInput $component, mixed $state, mixed $old): void {
            if (! $component->isAutoLookupEnabled()) {
                return;
            }

            $zipcode = is_string($state) ? PostalCodeNormalizer::tryNormalize($state) : null;

            // Typing or re-formatting the same postal code must not hit the driver again.
            if ($zipcode === null || (is_string($old) && PostalCodeNormalizer::tryNormalize($old) === $zipcode)) {
                return;
            }

            $component->lookupAddress($zipcode);
        });

        $this->dehydrateStateUsing(static fn (PostalCodeInput $component, mixed $state): ?string => $component->toStorageFormat($state));

        $this->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && (! is_string($value) || PostalCodeNormalizer::tryNormalize($value) === null)) {
                $fail(__('filament-jp-postal::messages.invalid'));
            }
        });

        $this->suffixAction(static fn (PostalCodeInput $component): Action => $component->getLookupAction());
    }

    /**
     * Runs after the conversion to the display format instead of replacing it.
     * formatStateUsing() goes through here too.
     */
    public function afterStateHydrated(?Closure $callback): static
    {
        $this->afterStateHydratedUsing = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function dehydrateState(array &$state, bool $isDehydrated = true): void
    {
        parent::dehydrateState($state, $isDehydrated);

        // The record of filled fields is UI state only. Top-level keys without a component are pruned by
        // Filament, but inside a Repeater item the key would otherwise be saved along with the item.
        $segments = explode('.', $this->getStatePath().self::FILLED_FIELDS_STATE_SUFFIX);
        $key = array_pop($segments);
        $container = &$state;

        foreach ($segments as $segment) {
            if (! isset($container[$segment]) || ! is_array($container[$segment])) {
                return;
            }

            $container = &$container[$segment];
        }

        unset($container[$key]);
    }

    public function autoLookup(bool|Closure $condition = true): static
    {
        $this->isAutoLookupEnabled = $condition;

        return $this;
    }

    public function lookupAction(bool|Closure $condition = true): static
    {
        $this->isLookupActionEnabled = $condition;

        return $this;
    }

    public function storeWithHyphen(bool|Closure $condition = true): static
    {
        $this->shouldStoreWithHyphen = $condition;

        return $this;
    }

    public function overwrite(bool|Closure $condition = true): static
    {
        $this->shouldOverwrite = $condition;

        return $this;
    }

    public function driver(string|Closure|null $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Paths are relative to this field's container. Use $cityTown to put 市区町村 and 町域 in one field.
     */
    public function fillsAddress(
        ?string $prefecture = null,
        ?string $prefectureCode = null,
        ?string $city = null,
        ?string $town = null,
        ?string $cityTown = null,
    ): static {
        $this->addressFields = [
            'prefecture' => $prefecture,
            'prefectureCode' => $prefectureCode,
            'city' => $city,
            'town' => $town,
            'cityTown' => $cityTown,
        ];

        return $this;
    }

    public function isAutoLookupEnabled(): bool
    {
        return (bool) $this->evaluate($this->isAutoLookupEnabled);
    }

    public function isLookupActionEnabled(): bool
    {
        return (bool) $this->evaluate($this->isLookupActionEnabled);
    }

    public function shouldStoreWithHyphen(): bool
    {
        return (bool) $this->evaluate($this->shouldStoreWithHyphen);
    }

    public function shouldOverwrite(): bool
    {
        return (bool) $this->evaluate($this->shouldOverwrite);
    }

    public function getDriver(): ?string
    {
        return $this->evaluate($this->driver);
    }

    /**
     * @return array{prefecture: ?string, prefectureCode: ?string, city: ?string, town: ?string, cityTown: ?string}
     */
    public function getAddressFields(): array
    {
        return $this->addressFields;
    }

    public function getLookupAction(): Action
    {
        return Action::make(self::LOOKUP_ACTION)
            ->label(__('filament-jp-postal::messages.lookup'))
            ->icon('heroicon-m-magnifying-glass')
            ->visible(static fn (PostalCodeInput $component): bool => $component->isLookupActionEnabled())
            ->disabled(static fn (PostalCodeInput $component): bool => $component->isDisabled())
            ->action(static function (PostalCodeInput $component): void {
                $state = $component->getRawState();
                $zipcode = is_string($state) ? PostalCodeNormalizer::tryNormalize($state) : null;

                if ($zipcode === null) {
                    Notification::make()
                        ->title(__('filament-jp-postal::messages.invalid'))
                        ->warning()
                        ->send();

                    return;
                }

                $component->lookupAddress($zipcode);
            });
    }

    /**
     * Looks up a normalized 7-digit postal code and fills the address fields.
     */
    public function lookupAddress(string $zipcode): void
    {
        try {
            $addresses = JpPostal::driver($this->getDriver())->lookup($zipcode);
        } catch (PostalLookupFailedException $e) {
            report($e);

            Notification::make()
                ->title(__('filament-jp-postal::messages.lookup_failed'))
                ->body(__('filament-jp-postal::messages.lookup_failed_body'))
                ->danger()
                ->send();

            return;
        }

        if ($addresses->isEmpty()) {
            $this->clearPreviouslyFilledFields();

            Notification::make()
                ->title(__('filament-jp-postal::messages.not_found'))
                ->warning()
                ->send();

            return;
        }

        if ($addresses->count() === 1) {
            /** @var Address $address */
            $address = $addresses->first();

            $this->fillAddress($address->prefecture, $address->prefectureCode, $address->city, $address->town);

            return;
        }

        $this->fillAddress(
            prefecture: $this->commonValue($addresses, 'prefecture'),
            prefectureCode: $this->commonValue($addresses, 'prefectureCode'),
            city: $this->commonValue($addresses, 'city'),
            town: null,
        );

        Notification::make()
            ->title(__('filament-jp-postal::messages.multiple_towns'))
            ->body(__('filament-jp-postal::messages.multiple_towns_body'))
            ->warning()
            ->send();
    }

    public function toDisplayFormat(mixed $state): mixed
    {
        if (is_int($state)) {
            $state = str_pad((string) $state, 7, '0', STR_PAD_LEFT);
        }

        $zipcode = is_string($state) ? PostalCodeNormalizer::tryNormalize($state) : null;

        return $zipcode === null ? $state : PostalCodeNormalizer::format($zipcode);
    }

    public function toStorageFormat(mixed $state): ?string
    {
        if (blank($state) || ! is_string($state)) {
            return null;
        }

        $zipcode = PostalCodeNormalizer::tryNormalize($state);

        if ($zipcode === null) {
            return $state;
        }

        return $this->shouldStoreWithHyphen() ? PostalCodeNormalizer::format($zipcode) : $zipcode;
    }

    protected function fillAddress(?string $prefecture, ?string $prefectureCode, ?string $city, ?string $town): void
    {
        $values = [
            'prefecture' => $prefecture,
            'prefectureCode' => $prefectureCode,
            'city' => $city,
            'town' => $town,
            'cityTown' => $city === null ? null : $city.$town,
        ];

        $get = $this->makeGetUtility();
        $set = $this->makeSetUtility();
        $filled = [];

        foreach ($this->getAddressFields() as $key => $path) {
            if ($path === null || (! $this->shouldOverwrite() && filled($get($path)))) {
                continue;
            }

            $value = blank($values[$key]) ? null : $values[$key];
            $set($path, $value);

            if ($value !== null) {
                $filled[$path] = $value;
            }
        }

        $this->rememberFilledFields($filled);
    }

    /**
     * Empties the fields the previous lookup filled, unless the user has changed them since.
     */
    protected function clearPreviouslyFilledFields(): void
    {
        if (! $this->shouldOverwrite()) {
            return;
        }

        $get = $this->makeGetUtility();
        $set = $this->makeSetUtility();
        $previouslyFilled = $get($this->getFilledFieldsStatePath());

        if (is_array($previouslyFilled)) {
            foreach ($this->getAddressFields() as $path) {
                if ($path !== null && array_key_exists($path, $previouslyFilled) && $get($path) === $previouslyFilled[$path]) {
                    $set($path, null);
                }
            }
        }

        $this->rememberFilledFields([]);
    }

    /**
     * Component objects are rebuilt on every Livewire request, so the values filled by the last lookup
     * are kept next to this field's state, which also gives every Repeater item its own record.
     *
     * @param  array<string, string>  $filled  target path => value
     */
    protected function rememberFilledFields(array $filled): void
    {
        $this->makeSetUtility()($this->getFilledFieldsStatePath(), $filled === [] ? null : $filled);
    }

    protected function getFilledFieldsStatePath(): string
    {
        return $this->getStatePath(isAbsolute: false).self::FILLED_FIELDS_STATE_SUFFIX;
    }

    /**
     * @param  Collection<int, Address>  $addresses
     */
    private function commonValue(Collection $addresses, string $property): ?string
    {
        $values = $addresses->pluck($property)->unique();

        return $values->count() === 1 ? $values->first() : null;
    }
}
