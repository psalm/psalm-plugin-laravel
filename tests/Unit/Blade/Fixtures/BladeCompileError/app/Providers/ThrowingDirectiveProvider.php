<?php

declare(strict_types=1);

namespace BladeCompileErrorFixture\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for a package directive whose callback throws at compile time, so the real Blade
 * compiler fails on any template that uses `@explode`.
 */
final class ThrowingDirectiveProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blade::directive('explode', static function (): never {
            throw new \LogicException('the explode directive needs a request');
        });
    }
}
