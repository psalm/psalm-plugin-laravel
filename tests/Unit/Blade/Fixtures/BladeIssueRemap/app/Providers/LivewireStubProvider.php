<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for Livewire's own precompiler: replaces a marker with an untyped closure and an
 * over-arity method call, the same shape Livewire 3's `<livewire: .../>` tag compiles to (#1498).
 * A real `livewire/livewire` dependency is deliberately avoided; only the generated CODE SHAPE
 * matters for this fixture, not the package that produces it.
 */
final class LivewireStubProvider extends ServiceProvider
{
    public const MARKER = '@@livewireStubMarker@@';

    public function boot(): void
    {
        Blade::precompiler(static function (string $value): string {
            if (!\str_contains($value, self::MARKER)) {
                return $value;
            }

            // A well-typed receiver, deliberately not the `app()` helper: an unnarrowed container
            // call resolves to `mixed`, and Psalm never checks argument count against `mixed`, so
            // the fixture would never raise the TooManyArguments this test exists to suppress.
            $generated = "<?php \$__split = function (\$__id, \$__params) { return [\$__id, \$__params]; };"
                . ' (new \BladeIssueRemapFixture\LivewireMountTarget())'
                . "->mount('id', 'params', 'key', 'extra1', 'extra2'); ?>";

            return \str_replace(self::MARKER, $generated, $value);
        });
    }
}
