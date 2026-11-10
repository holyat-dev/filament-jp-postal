<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Holyat\FilamentJpPostal\Contracts\PostalCodeDriver;
use Holyat\FilamentJpPostal\Data\Address;
use Holyat\FilamentJpPostal\Exceptions\PostalLookupFailedException;
use Holyat\FilamentJpPostal\Facades\JpPostal;
use Holyat\FilamentJpPostal\Forms\Components\PostalCodeInput;
use Holyat\FilamentJpPostal\Forms\Components\PrefectureSelect;
use Holyat\FilamentJpPostal\Tests\Fixtures\FakeDriver;
use Holyat\FilamentJpPostal\Tests\Fixtures\Livewire\TestForm;
use Illuminate\Support\Collection;
use Livewire\Livewire;

function chiyoda(): Address
{
    return new Address('1000001', '13', '東京都', '千代田区', '千代田', 'トウキョウト', 'チヨダク', 'チヨダ', '13101');
}

/**
 * @param  string  $key  The component key, i.e. the state path without the form's `data.` prefix.
 */
function lookupAction(string $key = 'zipcode'): TestAction
{
    return TestAction::make(PostalCodeInput::LOOKUP_ACTION)->schemaComponent($key);
}

/**
 * @param  array<int, mixed>  $extra
 * @return Closure(): array<int, mixed>
 */
function addressForm(Closure $configure, array $extra = []): Closure
{
    return fn (): array => [
        $configure(PostalCodeInput::make('zipcode')->fillsAddress(prefecture: 'prefecture', city: 'city', town: 'town')),
        PrefectureSelect::make('prefecture'),
        TextInput::make('city'),
        TextInput::make('town'),
        ...$extra,
    ];
}

beforeEach(function (): void {
    $fake = $this->fake = new FakeDriver([
        '1000001' => [chiyoda()],
        '0295523' => [
            new Address('0295523', '03', '岩手県', '和賀郡西和賀町', '越中畑'),
            new Address('0295523', '03', '岩手県', '和賀郡西和賀町', '芦ケ沢７２地割'),
        ],
        '9999998' => [
            new Address('9999998', '13', '東京都', '千代田区', 'A'),
            new Address('9999998', '13', '東京都', '中央区', 'B'),
        ],
    ]);

    JpPostal::extend('fake', fn () => $fake);
    config()->set('jp-postal.default', 'fake');

    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input);
    TestForm::$initialState = [];
});

it('has sensible defaults', function (): void {
    $input = PostalCodeInput::make('zipcode');

    expect($input->getPlaceholder())->toBe('100-0001')
        ->and($input->getAutocomplete())->toBe('postal-code')
        ->and($input->getInputMode())->toBe('numeric')
        ->and($input->isAutoLookupEnabled())->toBeTrue()
        ->and($input->isLookupActionEnabled())->toBeTrue()
        ->and($input->shouldStoreWithHyphen())->toBeFalse()
        ->and($input->shouldOverwrite())->toBeTrue();
});

it('formats the input on the client instead of using a mask', function (): void {
    $input = PostalCodeInput::make('zipcode');

    // mask() rejects full-width digits typed through a Japanese IME, and maxlength would cut a pasted 「〒100-0001」.
    expect($input->getMask())->toBeNull()
        ->and($input->getMaxLength())->toBeNull()
        ->and($input->getExtraAlpineAttributes())->toHaveKeys(['x-init', 'x-on:input', 'x-on:compositionend'])
        ->and($input->getExtraAlpineAttributes()['x-on:input'])->toContain('$event.isComposing')
        ->and($input->getExtraAlpineAttributes()['x-init'])->not->toContain('"');

    Livewire::test(TestForm::class)
        ->assertSeeHtml('inputmode="numeric"')
        ->assertSeeHtml('x-on:compositionend="$el.jpPostalFormat()"')
        ->assertDontSeeHtml('x-mask')
        ->assertDontSeeHtml('maxlength');
});

it('keeps extra alpine attributes added by the user', function (): void {
    $input = PostalCodeInput::make('zipcode')->extraAlpineAttributes(['x-on:focus' => 'console.log(1)'], merge: true);

    expect($input->getExtraAlpineAttributes())->toHaveKeys(['x-init', 'x-on:input', 'x-on:compositionend', 'x-on:focus']);
});

it('fills the address automatically once seven digits are entered', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->assertSet('data.prefecture', '東京都')
        ->assertSet('data.city', '千代田区')
        ->assertSet('data.town', '千代田');

    expect($this->fake->lookups)->toBe(['1000001']);
});

it('accepts full-width input for the automatic lookup', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '１００－０００１')
        ->assertSet('data.city', '千代田区');
});

it('does not look up incomplete postal codes', function (string $value): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', $value)
        ->assertSet('data.city', null);

    expect($this->fake->lookups)->toBe([]);
})->with(['100', '100-000', '100-00011', '']);

it('does not look up the same postal code again', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->set('data.zipcode', '1000001')
        ->set('data.zipcode', '〒100-0001');

    expect($this->fake->lookups)->toBe(['1000001']);
});

it('looks up again after the postal code changes', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->set('data.zipcode', '029-5523')
        ->set('data.zipcode', '100-0001');

    expect($this->fake->lookups)->toBe(['1000001', '0295523', '1000001']);
});

it('does not look up automatically when auto lookup is off', function (): void {
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->autoLookup(false));

    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->assertSet('data.city', null)
        ->callAction(lookupAction())
        ->assertSet('data.city', '千代田区');

    expect($this->fake->lookups)->toBe(['1000001']);
});

it('looks up with the action even for the same postal code', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->set('data.city', '書き換え')
        ->callAction(lookupAction())
        ->assertSet('data.city', '千代田区');

    expect($this->fake->lookups)->toBe(['1000001', '1000001']);
});

it('warns when the action is used with an invalid postal code', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-00')
        ->callAction(lookupAction())
        ->assertNotified(Notification::make()->title(__('filament-jp-postal::messages.invalid'))->warning());

    expect($this->fake->lookups)->toBe([]);
});

it('hides the action when it is turned off', function (): void {
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->lookupAction(false));

    Livewire::test(TestForm::class)->assertActionHidden(lookupAction());
});

it('shows the action by default', function (): void {
    Livewire::test(TestForm::class)->assertActionVisible(lookupAction());
});

it('fills only the common prefecture and city when several towns match', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.town', '前の町域')
        ->set('data.zipcode', '029-5523')
        ->assertSet('data.prefecture', '岩手県')
        ->assertSet('data.city', '和賀郡西和賀町')
        ->assertSet('data.town', null)
        ->assertNotified(Notification::make()
            ->title(__('filament-jp-postal::messages.multiple_towns'))
            ->body(__('filament-jp-postal::messages.multiple_towns_body'))
            ->warning());
});

it('leaves fields that differ between the results empty', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '999-9998')
        ->assertSet('data.prefecture', '東京都')
        ->assertSet('data.city', null)
        ->assertSet('data.town', null);
});

it('warns when nothing matches and keeps the fields', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.city', '入力済み')
        ->set('data.zipcode', '999-9999')
        ->assertSet('data.city', '入力済み')
        ->assertNotified(Notification::make()->title(__('filament-jp-postal::messages.not_found'))->warning());
});

it('clears the fields filled by the previous lookup when nothing matches', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '029-5523')
        ->assertSet('data.prefecture', '岩手県')
        ->assertSet('data.city', '和賀郡西和賀町')
        ->set('data.zipcode', '999-9999')
        ->assertSet('data.prefecture', null)
        ->assertSet('data.city', null)
        ->assertSet('data.town', null)
        ->assertNotified(Notification::make()->title(__('filament-jp-postal::messages.not_found'))->warning());
});

it('clears the previous result even after an incomplete value was typed in between', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->set('data.zipcode', '100-000')
        ->set('data.zipcode', '999-9999')
        ->assertSet('data.prefecture', null)
        ->assertSet('data.city', null)
        ->assertSet('data.town', null);
});

it('keeps values the user changed after the previous lookup', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->set('data.town', '丸の内')
        ->set('data.zipcode', '999-9999')
        ->assertSet('data.prefecture', null)
        ->assertSet('data.city', null)
        ->assertSet('data.town', '丸の内');
});

it('keeps values the user typed when the previous lookup left the field empty', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '029-5523')
        ->set('data.town', '越中畑')
        ->set('data.zipcode', '999-9999')
        ->assertSet('data.city', null)
        ->assertSet('data.town', '越中畑');
});

it('clears fields filled by the lookup action when nothing matches', function (): void {
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->autoLookup(false));

    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->callAction(lookupAction())
        ->assertSet('data.city', '千代田区')
        ->set('data.zipcode', '999-9999')
        ->callAction(lookupAction())
        ->assertSet('data.city', null);
});

it('does not clear anything when nothing matches and overwrite is off', function (): void {
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->overwrite(false));

    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->assertSet('data.city', '千代田区')
        ->set('data.zipcode', '999-9999')
        ->assertSet('data.prefecture', '東京都')
        ->assertSet('data.city', '千代田区')
        ->assertSet('data.town', '千代田');
});

it('keeps fields after a failed lookup', function (): void {
    $fake = $this->fake;
    JpPostal::extend('flaky', fn () => new class($fake) implements PostalCodeDriver
    {
        public function __construct(private FakeDriver $fake) {}

        public function lookup(string $zipcode): Collection
        {
            if ($zipcode === '9999999') {
                throw PostalLookupFailedException::unexpectedStatus('flaky', 500);
            }

            return $this->fake->lookup($zipcode);
        }
    });
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->driver('flaky'));

    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->set('data.zipcode', '999-9999')
        ->assertSet('data.city', '千代田区');
});

it('records the filled fields next to the field in the form data', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->assertSet('data.zipcode__jp_postal_filled', ['prefecture' => '東京都', 'city' => '千代田区', 'town' => '千代田'])
        ->set('data.zipcode', '999-9999')
        ->assertSet('data.zipcode__jp_postal_filled', null);
});

it('leaves the record of filled fields out of the saved state', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->assertSet('data.zipcode__jp_postal_filled', fn (mixed $value): bool => is_array($value))
        ->call('save')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $state): bool => $state === [
            'zipcode' => '1000001',
            'prefecture' => '東京都',
            'city' => '千代田区',
            'town' => '千代田',
        ]);
});

it('keeps a separate record per repeater item and leaves it out of the saved items', function (): void {
    TestForm::$components = fn (): array => [
        Repeater::make('addresses')->schema([
            PostalCodeInput::make('zipcode')->fillsAddress(prefecture: 'prefecture', city: 'city', town: 'town'),
            PrefectureSelect::make('prefecture'),
            TextInput::make('city'),
            TextInput::make('town'),
        ]),
    ];
    TestForm::$initialState = ['addresses' => [
        ['zipcode' => null, 'prefecture' => null, 'city' => null, 'town' => null],
        ['zipcode' => null, 'prefecture' => null, 'city' => null, 'town' => null],
    ]];

    $component = Livewire::test(TestForm::class);
    [$first, $second] = array_keys($component->get('data.addresses'));

    $component
        ->set("data.addresses.{$first}.zipcode", '100-0001')
        ->set("data.addresses.{$second}.zipcode", '029-5523')
        ->assertSet("data.addresses.{$first}.zipcode__jp_postal_filled", ['prefecture' => '東京都', 'city' => '千代田区', 'town' => '千代田'])
        ->assertSet("data.addresses.{$second}.zipcode__jp_postal_filled", ['prefecture' => '岩手県', 'city' => '和賀郡西和賀町'])
        ->set("data.addresses.{$second}.zipcode", '999-9999')
        ->assertSet("data.addresses.{$second}.prefecture", null)
        ->assertSet("data.addresses.{$second}.city", null)
        ->assertSet("data.addresses.{$first}.city", '千代田区')
        ->set("data.addresses.{$second}.zipcode", '029-5523')
        ->call('save')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $state): bool => array_values($state['addresses']) === [
            ['zipcode' => '1000001', 'prefecture' => '東京都', 'city' => '千代田区', 'town' => '千代田'],
            ['zipcode' => '0295523', 'prefecture' => '岩手県', 'city' => '和賀郡西和賀町', 'town' => null],
        ]);
});

it('looks up with the action of the repeater item it belongs to', function (Closure $wrap): void {
    TestForm::$components = fn (): array => [
        Repeater::make('addresses')->schema([
            $wrap(PostalCodeInput::make('zipcode')->autoLookup(false)->fillsAddress(prefectureCode: 'pref_code', city: 'city', town: 'town')),
            PrefectureSelect::make('pref_code')->codes(fn (): bool => true),
            TextInput::make('city'),
            TextInput::make('town'),
        ]),
    ];
    TestForm::$initialState = ['addresses' => [
        ['zipcode' => '100-0001', 'pref_code' => null, 'city' => null, 'town' => null],
        ['zipcode' => '029-5523', 'pref_code' => null, 'city' => null, 'town' => null],
    ]];

    $component = Livewire::test(TestForm::class);
    [$first, $second] = array_keys($component->get('data.addresses'));

    $component
        ->callAction(lookupAction("addresses.{$second}.zipcode"))
        ->assertSet("data.addresses.{$second}.pref_code", '03')
        ->assertSet("data.addresses.{$second}.city", '和賀郡西和賀町')
        ->assertSet("data.addresses.{$first}.city", null)
        ->callAction(lookupAction("addresses.{$first}.zipcode"))
        ->assertSet("data.addresses.{$first}.pref_code", '13')
        ->assertSet("data.addresses.{$first}.city", '千代田区')
        ->assertSet("data.addresses.{$second}.city", '和賀郡西和賀町')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->fake->lookups)->toBe(['0295523', '1000001']);
})->with([
    'directly in the item' => [fn (PostalCodeInput $input) => $input],
    'inside a section' => [fn (PostalCodeInput $input) => Section::make()->schema([$input])],
]);

it('leaves the record of filled fields out of saved repeater items when the field is not dehydrated', function (Closure $configure, array $expectedItem): void {
    TestForm::$components = fn (): array => [
        Repeater::make('addresses')->schema([
            Toggle::make('hide_zipcode'),
            $configure(PostalCodeInput::make('zipcode')->fillsAddress(prefecture: 'prefecture', city: 'city', town: 'town')),
            PrefectureSelect::make('prefecture'),
            TextInput::make('city'),
            TextInput::make('town'),
        ]),
    ];
    TestForm::$initialState = ['addresses' => [
        ['hide_zipcode' => false, 'zipcode' => null, 'prefecture' => null, 'city' => null, 'town' => null],
    ]];

    $component = Livewire::test(TestForm::class);
    $item = array_key_first($component->get('data.addresses'));

    $component
        ->set("data.addresses.{$item}.zipcode", '100-0001')
        ->assertSet("data.addresses.{$item}.zipcode__jp_postal_filled", fn (mixed $value): bool => is_array($value))
        ->set("data.addresses.{$item}.hide_zipcode", true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $state): bool => array_values($state['addresses']) === [$expectedItem]);
})->with([
    'hidden()' => [
        fn (PostalCodeInput $input) => $input->hidden(fn (Get $get): bool => (bool) $get('hide_zipcode')),
        ['hide_zipcode' => true, 'prefecture' => '東京都', 'city' => '千代田区', 'town' => '千代田'],
    ],
    'visible(false)' => [
        fn (PostalCodeInput $input) => $input->visible(fn (Get $get): bool => ! $get('hide_zipcode')),
        ['hide_zipcode' => true, 'prefecture' => '東京都', 'city' => '千代田区', 'town' => '千代田'],
    ],
    'hidden() but dehydrated when hidden' => [
        fn (PostalCodeInput $input) => $input->hidden(fn (Get $get): bool => (bool) $get('hide_zipcode'))->dehydratedWhenHidden(),
        ['hide_zipcode' => true, 'zipcode' => '1000001', 'prefecture' => '東京都', 'city' => '千代田区', 'town' => '千代田'],
    ],
    'dehydrated(false)' => [
        fn (PostalCodeInput $input) => $input->dehydrated(false),
        ['hide_zipcode' => true, 'prefecture' => '東京都', 'city' => '千代田区', 'town' => '千代田'],
    ],
    'inside a hidden section' => [
        fn (PostalCodeInput $input) => Section::make()->hidden(fn (Get $get): bool => (bool) $get('hide_zipcode'))->schema([$input]),
        ['hide_zipcode' => true, 'prefecture' => '東京都', 'city' => '千代田区', 'town' => '千代田'],
    ],
]);

it('reports a failed lookup without blocking the form', function (): void {
    JpPostal::extend('failing', fn () => new class implements PostalCodeDriver
    {
        public function lookup(string $zipcode): Collection
        {
            throw PostalLookupFailedException::unexpectedStatus('failing', 500);
        }
    });
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->driver('failing'));

    Livewire::test(TestForm::class)
        ->set('data.city', '入力済み')
        ->set('data.zipcode', '100-0001')
        ->assertSet('data.city', '入力済み')
        ->assertNotified(Notification::make()
            ->title(__('filament-jp-postal::messages.lookup_failed'))
            ->body(__('filament-jp-postal::messages.lookup_failed_body'))
            ->danger())
        ->call('save')
        ->assertHasNoErrors();
});

it('does not overwrite filled fields when overwrite is off', function (): void {
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->overwrite(false));

    Livewire::test(TestForm::class)
        ->set('data.city', '入力済み')
        ->set('data.zipcode', '100-0001')
        ->assertSet('data.prefecture', '東京都')
        ->assertSet('data.city', '入力済み')
        ->assertSet('data.town', '千代田');
});

it('does not clear filled fields for several towns when overwrite is off', function (): void {
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->overwrite(false));

    Livewire::test(TestForm::class)
        ->set('data.town', '入力済み')
        ->set('data.zipcode', '029-5523')
        ->assertSet('data.town', '入力済み');
});

it('fills the prefecture code and a combined city and town field', function (): void {
    TestForm::$components = fn (): array => [
        PostalCodeInput::make('zipcode')->fillsAddress(prefectureCode: 'pref_code', cityTown: 'address'),
        PrefectureSelect::make('pref_code')->codes(),
        TextInput::make('address'),
    ];

    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->assertSet('data.pref_code', '13')
        ->assertSet('data.address', '千代田区千代田')
        ->set('data.zipcode', '029-5523')
        ->assertSet('data.pref_code', '03')
        ->assertSet('data.address', '和賀郡西和賀町');
});

it('uses the driver set on the field', function (): void {
    $other = new FakeDriver(['1000001' => [chiyoda()]]);
    JpPostal::extend('other', fn () => $other);
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->driver('other'));

    Livewire::test(TestForm::class)->set('data.zipcode', '100-0001');

    expect($other->lookups)->toBe(['1000001'])
        ->and($this->fake->lookups)->toBe([]);
});

it('stores seven digits by default', function (): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', '100-0001')
        ->call('save')
        ->assertReturned(fn (array $state): bool => $state['zipcode'] === '1000001');
});

it('stores with a hyphen after storeWithHyphen()', function (): void {
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->storeWithHyphen());

    Livewire::test(TestForm::class)
        ->set('data.zipcode', '１００００01')
        ->call('save')
        ->assertReturned(fn (array $state): bool => $state['zipcode'] === '100-0001');
});

it('stores null when empty', function (): void {
    Livewire::test(TestForm::class)
        ->call('save')
        ->assertReturned(fn (array $state): bool => $state['zipcode'] === null);
});

it('displays stored values with a hyphen whatever the stored format', function (mixed $stored): void {
    TestForm::$initialState = ['zipcode' => $stored];

    Livewire::test(TestForm::class)->assertSet('data.zipcode', '100-0001');

    expect($this->fake->lookups)->toBe([]);
})->with(['1000001', '100-0001', '１００－０００１', '〒100-0001']);

it('restores leading zeros of postal codes stored as integers', function (): void {
    TestForm::$initialState = ['zipcode' => 600000];

    Livewire::test(TestForm::class)->assertSet('data.zipcode', '060-0000');
});

it('runs a user formatStateUsing() callback after the display format conversion', function (): void {
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->formatStateUsing(fn (?string $state): string => "〒{$state}"));
    TestForm::$initialState = ['zipcode' => '1000001'];

    Livewire::test(TestForm::class)->assertSet('data.zipcode', '〒100-0001');
});

it('runs a user afterStateHydrated() callback after the display format conversion', function (): void {
    $received = new ArrayObject;
    $callback = function (PostalCodeInput $component, ?string $state) use ($received): void {
        $received[] = $state;
    };
    TestForm::$components = addressForm(fn (PostalCodeInput $input) => $input->afterStateHydrated($callback));
    TestForm::$initialState = ['zipcode' => '1000001'];

    Livewire::test(TestForm::class)->assertSet('data.zipcode', '100-0001');

    expect($received->getArrayCopy())->toContain('100-0001')->not->toContain('1000001');
});

it('rejects values that are not postal codes', function (string $value): void {
    Livewire::test(TestForm::class)
        ->set('data.zipcode', $value)
        ->call('save')
        ->assertHasErrors('data.zipcode');
})->with(['12-34', 'abcdefg', '100-000a']);

it('fills the fields of its own repeater item using relative paths', function (): void {
    TestForm::$components = fn (): array => [
        Repeater::make('addresses')->schema([
            PostalCodeInput::make('zipcode')->fillsAddress(prefecture: 'prefecture', city: 'city', town: 'town'),
            PrefectureSelect::make('prefecture'),
            TextInput::make('city'),
            TextInput::make('town'),
        ]),
    ];
    TestForm::$initialState = ['addresses' => [
        ['zipcode' => null, 'prefecture' => null, 'city' => null, 'town' => null],
        ['zipcode' => null, 'prefecture' => null, 'city' => null, 'town' => null],
    ]];

    $component = Livewire::test(TestForm::class);
    [$first, $second] = array_keys($component->get('data.addresses'));

    $component
        ->set("data.addresses.{$second}.zipcode", '100-0001')
        ->assertSet("data.addresses.{$second}.prefecture", '東京都')
        ->assertSet("data.addresses.{$second}.city", '千代田区')
        ->assertSet("data.addresses.{$second}.town", '千代田')
        ->assertSet("data.addresses.{$first}.city", null)
        ->set("data.addresses.{$first}.zipcode", '029-5523')
        ->assertSet("data.addresses.{$first}.city", '和賀郡西和賀町')
        ->assertSet("data.addresses.{$second}.city", '千代田区')
        ->callAction(lookupAction("addresses.{$first}.zipcode"))
        ->assertSet("data.addresses.{$first}.prefecture", '岩手県')
        ->assertSet("data.addresses.{$second}.prefecture", '東京都');
});
