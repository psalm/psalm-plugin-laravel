<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\BoundClosures;

/**
 * Closures shaped like directives a service provider registers in `boot()`: plain non-static
 * closures, so PHP binds every one of them to this instance (#1693). Each returns a closure
 * literal on its own lines so a token scan of one body never sees another's.
 */
final class BoundClosureProvider extends BaseClosureProvider
{
    public const PREFIX = 'self';

    private string $mark = 'state';

    public function readsThis(): \Closure
    {
        return function (string $expression): string {
            return '<?php echo "' . $this->mark . '"; ?>';
        };
    }

    // Sits between bodies that DO reach the object, so a scan leaking past its own lines fails.
    public function ignoresThis(): \Closure
    {
        return function (string $expression): string {
            // $this in a comment, and in a single-quoted string below, never reads the object.
            return '<?php echo \'$this\'; ?>';
        };
    }

    public function arrowFnIgnoresThis(): \Closure
    {
        return fn(string $expression): string => '<?php ?>';
    }

    public function interpolatesThis(): \Closure
    {
        return function (string $expression): string {
            return "<?php echo '{$this->mark}'; ?>";
        };
    }

    public function nestedArrowFnReadsThis(): \Closure
    {
        return function (string $expression): string {
            return (fn(): string => $this->mark)();
        };
    }

    public function nestedClosureCarriesThis(): \Closure
    {
        return function (string $expression): string {
            // The inner arrow fn inherits the bound object without naming it.
            return \get_debug_type((new \ReflectionFunction(fn(): int => 0))->getClosureThis());
        };
    }

    public function nestedFunctionCarriesThis(): \Closure
    {
        return function (string $expression): string {
            return \print_r(function (): int {
                return 0;
            }, true);
        };
    }

    public function usesStatic(): \Closure
    {
        return function (string $expression): string {
            return static::PREFIX;
        };
    }

    public function usesSelf(): \Closure
    {
        return function (string $expression): string {
            return self::PREFIX;
        };
    }

    public function usesParent(): \Closure
    {
        return function (string $expression): string {
            return parent::PREFIX;
        };
    }

    public function usesVariableVariable(): \Closure
    {
        return function (string $expression): string {
            $name = 'this';

            return $$name->mark;
        };
    }

    public function usesEval(): \Closure
    {
        return function (string $expression): string {
            // Never executed: eval()'d code inherits the closure's $this.
            return eval('return $this->mark;'); // NOSONAR
        };
    }

    public function usesInclude(): \Closure
    {
        return function (string $expression): string {
            // Never executed: an included file inherits the closure's $this.
            return include __DIR__ . '/reads-this.php';
        };
    }

    public function usesCompact(): \Closure
    {
        return function (string $expression): string {
            return \get_debug_type(\compact('this'));
        };
    }

    public function usesDebugBacktrace(): \Closure
    {
        return function (string $expression): string {
            return \get_debug_type(\debug_backtrace()[0]['object'] ?? null);
        };
    }

    public function usesGetCalledClass(): \Closure
    {
        return function (string $expression): string {
            return \get_called_class();
        };
    }

    public function usesDebugPrintBacktrace(): \Closure
    {
        return function (string $expression): string {
            \debug_print_backtrace();

            return '<?php ?>';
        };
    }

    public function __invoke(string $expression): string
    {
        return '<?php ?>';
    }

    public function compileWithoutThis(string $expression): string
    {
        return '<?php ?>';
    }
}
