<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\ComposerBinding;

use Illuminate\Container\Attributes\Bind;

/** `Container::getConcrete()` resolves the class to what the attribute names, and `bound()` misses it. */
#[Bind(OtherComposer::class)]
final class AttributeBoundComposer {}
