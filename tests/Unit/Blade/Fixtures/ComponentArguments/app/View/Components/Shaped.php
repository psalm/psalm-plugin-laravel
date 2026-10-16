<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

/** Docblock types narrower than what a tag can prove: a value that may fit is not reported. */
final class Shaped extends Component
{
    /**
     * @param 'sm'|'md' $size
     * @param int<1, 10> $n
     * @param 1|2|3 $level
     * @param class-string<Component> $kind
     * @param list<string> $items
     * @param non-empty-string $label
     */
    public function __construct(
        public string $size = 'sm',
        public int $n = 1,
        public int $level = 1,
        public string $kind = Plain::class,
        public array $items = [],
        public string $label = 'label',
    ) {}

    public function render(): string
    {
        return '<div></div>';
    }
}
