<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for a vendor directive pair that wraps template content in a closure (TallStackUI's
 * `@interact('column', $row) ... @endinteract` table slots): everything between the two compiles
 * into a function scope the prelude's top-level declarations do not reach (#2130).
 */
final class ClosureDirectiveStubProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blade::directive('interact', static fn(): string
            => "<?php \$__env->slot('x', function(\$row) use (\$__env) { ?>");

        Blade::directive('endinteract', static fn(): string => '<?php }); ?>');
    }
}
