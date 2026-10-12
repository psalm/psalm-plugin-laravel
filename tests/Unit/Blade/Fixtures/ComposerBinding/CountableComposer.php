<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\ComposerBinding;

/** A before-resolving hook registered on `Countable` fires for this class too (`is_subclass_of()`). */
final class CountableComposer implements \Countable
{
    #[\Override]
    public function count(): int
    {
        return 0;
    }
}
