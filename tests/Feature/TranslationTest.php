<?php

declare(strict_types=1);

use Filament\Forms\Components\TextInput;
use Holyat\FilamentJpPostal\Forms\Components\PostalCodeInput;
use Holyat\FilamentJpPostal\Forms\Components\PrefectureSelect;
use Holyat\FilamentJpPostal\Tests\Fixtures\Livewire\TestForm;
use Livewire\Livewire;

const LOCALES = ['ja', 'en', 'ko'];

/**
 * @return array<string, string>
 */
function translations(string $locale): array
{
    return require __DIR__."/../../resources/lang/{$locale}/messages.php";
}

it('has the same keys in every locale', function (): void {
    $keys = array_keys(translations('ja'));

    foreach (LOCALES as $locale) {
        expect(array_keys(translations($locale)))->toBe($keys, "Keys differ in [{$locale}]");
    }
});

it('has no empty translations', function (string $locale): void {
    expect(translations($locale))->each->toBeString()->not->toBeEmpty();
})->with(LOCALES);

it('defines every translation key used in the source', function (): void {
    $source = implode('', array_map(file_get_contents(...), glob(__DIR__.'/../../src/{,*/,*/*/}*.php', GLOB_BRACE) ?: []));
    preg_match_all('/filament-jp-postal::messages\\.([a-z_]+)/', $source, $matches);

    expect(array_unique($matches[1]))->not->toBeEmpty()
        ->and(array_diff(array_unique($matches[1]), array_keys(translations('ja'))))->toBe([]);
});

it('loads translations through the package namespace', function (string $locale): void {
    app()->setLocale($locale);

    expect(__('filament-jp-postal::messages.postal_code'))->toBe(translations($locale)['postal_code']);
})->with(LOCALES);

it('labels the fields in the current locale', function (string $locale, string $postalCode, string $prefecture): void {
    app()->setLocale($locale);

    expect(PostalCodeInput::make('zipcode')->getLabel())->toBe($postalCode)
        ->and(PrefectureSelect::make('prefecture')->getLabel())->toBe($prefecture);
})->with([
    ['ja', '郵便番号', '都道府県'],
    ['en', 'Postal code', 'Prefecture'],
    ['ko', '우편번호', '도도부현'],
]);

it('lets a custom label replace the default one', function (): void {
    expect(PostalCodeInput::make('zipcode')->label('〒')->getLabel())->toBe('〒')
        ->and(PrefectureSelect::make('prefecture')->label('県')->getLabel())->toBe('県');
});

it('renders the translated labels and lookup action', function (): void {
    app()->setLocale('ja');
    TestForm::$components = fn (): array => [
        PostalCodeInput::make('zipcode')->fillsAddress(prefecture: 'prefecture', city: 'city'),
        PrefectureSelect::make('prefecture'),
        TextInput::make('city'),
    ];
    TestForm::$initialState = [];

    Livewire::test(TestForm::class)
        ->assertSee('郵便番号')
        ->assertSee('都道府県')
        ->assertSee('住所検索');
});
