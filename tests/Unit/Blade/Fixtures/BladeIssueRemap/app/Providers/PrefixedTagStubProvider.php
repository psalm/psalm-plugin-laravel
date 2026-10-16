<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\ComponentTagCompiler;

/**
 * Stands in for a precompiler that owns its own tag prefix (NativePHP's `<native:...>`, Flux's
 * `<flux:...>`): rewrites `<foo:name` to `<x-name` and compiles it with ComponentTagCompiler, the
 * same strict attribute matching Blade's own `<x-...>` tags get (#1910).
 */
final class PrefixedTagStubProvider extends ServiceProvider
{
    public function boot(): void
    {
        $blade = $this->app->make(BladeCompiler::class);

        $blade->precompiler(static function (string $value) use ($blade): string {
            if (!\str_contains($value, '<foo:')) {
                return $value;
            }

            $value = \str_replace(['</foo:', '<foo:'], ['</x-', '<x-'], $value);

            return (new ComponentTagCompiler($blade->getClassComponentAliases(), $blade->getClassComponentNamespaces(), $blade))
                ->compileTags($value);
        });
    }
}
