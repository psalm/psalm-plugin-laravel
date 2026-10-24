<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

final class Tpl extends Component
{
    /**
     * @template T
     * @param list<T> $values a template nested in the type is not compared
     */
    public function __construct(public array $values) {}

    public function render(): string
    {
        return '<div></div>';
    }
}
