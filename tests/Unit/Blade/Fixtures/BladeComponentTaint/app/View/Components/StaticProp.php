<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A static property is never handed to the view. */
final class StaticProp extends Component
{
    public static string $message = '';

    public function __construct(string $message)
    {
        self::$message = $message;
    }

    #[\Override]
    public function render(): View
    {
        return view('components.static-prop');
    }
}
