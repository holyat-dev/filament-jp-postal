<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Tests\Fixtures\Livewire;

use Closure;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * A bare Livewire form whose components are set per test through TestForm::$components.
 */
class TestForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var Closure(): array<int, mixed> */
    public static Closure $components;

    /** @var array<string, mixed> */
    public static array $initialState = [];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(static::$initialState);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components((static::$components)())
            ->statePath('data');
    }

    /**
     * @return array<string, mixed>
     */
    public function save(): array
    {
        return $this->form->getState();
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                {{ $this->form }}
                <x-filament-actions::modals />
            </div>
            BLADE;
    }
}
