<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Alert extends Component
{
    /** @var array<int, string> */
    public array $items = ['a'];

    /** A caller passes `<x-slot:footer>`, which overrides this key on that render. */
    public string $footer = '';

    /** @psalm-suppress MissingPropertyType no declared type: the view keeps the prelude's mixed. */
    public $untyped = 'x';

    public function __construct(public string $title = 'Hi', public string $label = 'label') {}

    /** Shares its name with summary(): `data()`'s array_merge() lets the method win. */
    public string $summary = '';

    public function summary(): string
    {
        return $this->summary;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function format(string $value): string
    {
        return $value;
    }

    public function render(): View
    {
        return view('components.alert');
    }
}
