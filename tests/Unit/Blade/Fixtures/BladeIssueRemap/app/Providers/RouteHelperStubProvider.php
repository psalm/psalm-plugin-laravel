<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for a real vendor package whose Blade directive compiles a class name into a PHP
 * STRING LITERAL rather than code position (`app('Vendor\Package\Class')::method()`), the shape
 * that makes the target unreachable by Psalm's own docblock/code-position scanning (#1505).
 * `@bogusroute` is the negative sibling: its literal names no real class and must change nothing.
 */
final class RouteHelperStubProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blade::directive('routes', static fn(): string
            => "<?php echo app('RouteHelperFixture\\\\RouteGenerator')::generate(); ?>");

        Blade::directive('bogusroute', static fn(): string
            => "<?php echo 'Not\\\\A\\\\Real\\\\ClassName'; ?>");
    }
}
