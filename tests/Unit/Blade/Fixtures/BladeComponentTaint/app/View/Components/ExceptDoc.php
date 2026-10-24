<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A docblock-typed $except is not a literal the plugin reads: it keeps every name (documented over-report). */
final class ExceptDoc extends Component
{
    /** @var array<array-key, mixed> */
    protected $except = ['message'];

    public function __construct(public string $message) {}

    #[\Override]
    public function render(): View
    {
        return view('components.except-doc');
    }
}
