<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\Forms\Components\PrefectureSelect;
use Holyat\FilamentJpPostal\Tests\Fixtures\Livewire\TestForm;
use Livewire\Livewire;

it('offers the 47 prefectures in JIS order with names as values', function (): void {
    $options = PrefectureSelect::make('prefecture')->getPrefectureOptions();

    expect($options)->toHaveCount(47)
        ->and(array_key_first($options))->toBe('北海道')
        ->and(array_key_last($options))->toBe('沖縄県')
        ->and($options['東京都'])->toBe('東京都');
});

it('uses JIS codes as values after codes()', function (): void {
    $options = PrefectureSelect::make('pref_code')->codes()->getPrefectureOptions();

    expect($options)->toHaveCount(47)
        ->and(array_map(strval(...), array_keys($options)))->toBe(array_map(fn (int $i): string => sprintf('%02d', $i), range(1, 47)))
        ->and($options['01'])->toBe('北海道')
        ->and($options['13'])->toBe('東京都')
        ->and($options['47'])->toBe('沖縄県');
});

it('is searchable by default', function (): void {
    expect(PrefectureSelect::make('prefecture')->isSearchable())->toBeTrue()
        ->and(PrefectureSelect::make('prefecture')->searchable(false)->isSearchable())->toBeFalse();
});

it('saves the prefecture name', function (): void {
    TestForm::$components = fn (): array => [PrefectureSelect::make('prefecture')];

    Livewire::test(TestForm::class)
        ->set('data.prefecture', '東京都')
        ->call('save')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $state): bool => $state === ['prefecture' => '東京都']);
});

it('saves the code as a string in code mode', function (string $code): void {
    TestForm::$components = fn (): array => [PrefectureSelect::make('pref_code')->codes()];

    Livewire::test(TestForm::class)
        ->set('data.pref_code', $code)
        ->call('save')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $state): bool => $state === ['pref_code' => $code]);
})->with(['01', '13', '47']);

it('rejects values that are not prefectures', function (Closure $component, string $value): void {
    TestForm::$components = fn (): array => [$component()];

    Livewire::test(TestForm::class)
        ->set('data.'.$component()->getName(), $value)
        ->call('save')
        ->assertHasErrors();
})->with([
    'unknown name' => [fn () => PrefectureSelect::make('prefecture'), '東京'],
    'code in name mode' => [fn () => PrefectureSelect::make('prefecture'), '13'],
    'unknown code' => [fn () => PrefectureSelect::make('pref_code')->codes(), '48'],
    'name in code mode' => [fn () => PrefectureSelect::make('pref_code')->codes(), '東京都'],
]);

it('allows an empty value unless required', function (): void {
    TestForm::$components = fn (): array => [PrefectureSelect::make('prefecture')];

    Livewire::test(TestForm::class)
        ->call('save')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $state): bool => $state === ['prefecture' => null]);

    TestForm::$components = fn (): array => [PrefectureSelect::make('prefecture')->required()];

    Livewire::test(TestForm::class)
        ->call('save')
        ->assertHasErrors(['data.prefecture' => 'required']);
});

it('saves several codes as strings when multiple', function (): void {
    TestForm::$components = fn (): array => [PrefectureSelect::make('pref_codes')->codes()->multiple()];

    Livewire::test(TestForm::class)
        ->set('data.pref_codes', ['01', '13'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $state): bool => $state === ['pref_codes' => ['01', '13']]);
});
