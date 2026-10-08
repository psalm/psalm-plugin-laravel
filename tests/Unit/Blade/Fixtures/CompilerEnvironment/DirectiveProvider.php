<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\CompilerEnvironment;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * Mimics a ServiceProvider's non-static `boot()`: every closure below is auto-bound to this
 * instance. Kept under Fixtures/ so Rector does not rewrite them to `static function`.
 */
final class DirectiveProvider
{
    public function __construct(private readonly string $prefix = 'money') {}

    public function registerIgnoringThis(BladeCompiler $compiler): void
    {
        $compiler->directive('money', function (string $expression): string {
            return "<?php echo number_format({$expression}, 2); ?>";
        });
    }

    public function registerReadingThis(BladeCompiler $compiler): void
    {
        $compiler->directive('money', function (string $expression): string {
            return "<?php echo '{$this->prefix}' . {$expression}; ?>";
        });
    }

    public function registerCallingInstanceMethodViaSelf(BladeCompiler $compiler): void
    {
        $compiler->directive('money', function (string $expression): string {
            return self::format($expression);
        });
    }

    public function registerCallingStaticMethodViaSelf(BladeCompiler $compiler): void
    {
        $compiler->directive('money', function (string $expression): string {
            return self::formatStatically($expression);
        });
    }

    public function registerCapturingObject(BladeCompiler $compiler): void
    {
        $service = new \stdClass();
        $compiler->precompiler(static function (string $value) use ($service): string {
            return $value . \get_debug_type($service);
        });
    }

    private function format(string $expression): string
    {
        return "<?php echo '{$this->prefix}' . {$expression}; ?>";
    }

    private static function formatStatically(string $expression): string
    {
        return "<?php echo {$expression}; ?>";
    }
}
