<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\ComposerBinding;

use Illuminate\Container\Attributes\BindWhen;

/** The attribute is read by name only and never instantiated, so it needs no condition here. */
#[BindWhen(OtherComposer::class)]
final class ConditionallyBoundComposer {}
