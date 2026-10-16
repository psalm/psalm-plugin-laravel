<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

/** Overrides `resolve()`, so its constructor is not what the tag's data reaches. */
final class Custom extends Component
{
    public function __construct(public int $count) {}

    /** @param array<string, mixed> $data */
    #[\Override]
    public static function resolve($data)
    {
        return new self((int) ($data['count'] ?? 0));
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
