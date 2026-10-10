<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Registers plain (non-static) closures from inside a service-provider method, so each closure is
 * bound to the provider instance: the shape of laravel/pennant's `@feature` and spatie/laravel-ray's
 * `@ray`.
 */
final class ProviderBoundDirectives extends ServiceProvider
{
    public function registerOn(BladeCompiler $compiler): void
    {
        $compiler->directive('ray', fn(string $expression): string => '');
        $compiler->if('feature', fn(string $name): bool => true);
    }
}
