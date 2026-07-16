<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\View\Factory;
use Illuminate\View\View;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\MissingView;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Detects calls to the view() helper, Factory::make(), and View facade
 * with a view name that does not correspond to an existing template file,
 * and narrows the view() helper's return type past the stub's contract
 * fallback to a concrete class.
 *
 * Registers for both the service class (Factory) and its facades/aliases
 * (View, \Illuminate\Support\Facades\View) via FacadeMapProvider, so the handler
 * fires regardless of how the developer calls make().
 *
 * Only string literal view names are checked for the MissingView diagnostic —
 * dynamic names and namespaced views (e.g., 'mail::html.header') are skipped
 * to avoid false positives.
 *
 * Type narrowing for view() is provenance-based on Laravel's own ground truth:
 * the helper branches on `func_num_args() === 0` (never on the value of the
 * first argument, so `view(null)` still takes the "argument supplied" branch).
 * Zero-arg calls narrow to the app's actual resolved view-factory class (a
 * bonus for a Factory subclass); argument-supplied calls narrow to the
 * concrete `\Illuminate\View\View` only when the resolved factory is the
 * stock `\Illuminate\View\Factory` — a subclass may override `viewInstance()`
 * and construct a different View implementation.
 *
 * @see https://laravel.com/docs/views
 */
final class MissingViewHandler implements FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    /** @var list<string> Absolute paths to view directories */
    private static array $viewPaths = [];

    /** @var list<string> File extensions to check (from FileViewFinder::getExtensions()) */
    private static array $extensions = ['blade.php', 'php'];

    private static bool $enabled = false;

    /** @var array<string, bool> Cached view existence results to avoid repeated filesystem checks */
    private static array $resolvedViews = [];

    /** @var class-string|null The booted app's resolved view-factory class, for view() helper narrowing */
    private static ?string $factoryClass = null;

    /** @var array<class-string, Union> cache of concrete return unions (Psalm 7 unions are immutable) */
    private static array $narrowedUnions = [];

    /**
     * Cached leading-spread union. Keyed on nothing — it is the same two contracts
     * every time and is app-independent, so it never needs resetting.
     */
    private static ?Union $spreadUnion = null;

    /**
     * Return to the disabled state before each application boot. The narrowed and
     * spread unions are immutable and independent of the application, so they are
     * deliberately retained.
     *
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$viewPaths = [];
        self::$extensions = ['blade.php', 'php'];
        self::$enabled = false;
        self::$resolvedViews = [];
        self::$factoryClass = null;
    }

    /**
     * @param list<string> $viewPaths Absolute paths to view directories (from config('view.paths'))
     * @param list<string> $extensions File extensions without leading dot (from FileViewFinder::getExtensions())
     * @psalm-external-mutation-free
     */
    public static function init(array $viewPaths, array $extensions = ['blade.php', 'php']): void
    {
        self::$viewPaths = \array_map(static fn(string $path): string => \rtrim(
            $path,
            \DIRECTORY_SEPARATOR,
        ), $viewPaths);
        self::$extensions = $extensions;
        self::$enabled = true;
        self::$resolvedViews = [];
    }

    /**
     * Record the booted app's resolved view-factory class for view() helper narrowing.
     *
     * Always called (regardless of findMissingViews) with the resolved class or null,
     * so a re-invocation in a reused process overwrites — never leaks — a prior app's
     * binding. Null disables the narrowing and the stub's contract fallback applies.
     *
     * @param class-string|null $class
     * @psalm-external-mutation-free
     */
    public static function initViewFactory(?string $class): void
    {
        self::$factoryClass = $class;
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['view'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $callArgs = $event->getCallArgs();

        // view(...$args) — a LEADING spread hides the argument count, so
        // func_num_args() could be 0 (an empty spread runs the zero-arg branch and
        // returns the factory) or not (returns a View). Return the sound union of
        // both contracts rather than defer to the stub, whose func_num_args()
        // conditional collapses a spread to the View branch — wrong for an empty
        // spread, and it would falsely accept a concrete-only call. A trailing
        // spread (`view('x', ...$data)`) hides neither the count (provably >= 1)
        // nor the name, so the diagnostic and narrowing below still apply.
        if ($callArgs !== [] && $callArgs[0]->unpack) {
            return self::spreadReturn();
        }

        if ($callArgs === []) {
            $narrowedClass = self::narrowedHelperReturn(0);

            if ($narrowedClass === null) {
                return null;
            }

            // The resolved class is the app's own binding, not our own vendored
            // code — guard against Psalm not having scanned it.
            if (!$event->getStatementsSource()->getCodebase()->classExists($narrowedClass)) {
                return null;
            }

            return self::narrowedUnion($narrowedClass);
        }

        $viewName = self::extractLiteralStringArg($callArgs[0]);

        if ($viewName !== null) {
            self::checkViewExists(
                $viewName,
                $event->getCodeLocation(),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }

        $narrowedClass = self::narrowedHelperReturn(\count($callArgs));

        return $narrowedClass !== null ? self::narrowedUnion($narrowedClass) : null;
    }

    /**
     * Decide which concrete class the view() helper narrows to, given only the
     * call's argument count — Laravel's helper branches on `func_num_args() === 0`,
     * never on the arguments' values.
     *
     * - Zero args: narrows to the app's actual resolved factory class (whatever
     *   it is) since `view()` always returns that instance directly.
     * - One or more args: narrows to `\Illuminate\View\View` only when the
     *   resolved factory is the stock `\Illuminate\View\Factory` — a subclass
     *   may override `viewInstance()` to construct a different implementation.
     *
     * @return class-string|null
     * @psalm-external-mutation-free
     */
    private static function narrowedHelperReturn(int $argCount): ?string
    {
        if ($argCount === 0) {
            return self::$factoryClass;
        }

        return self::$factoryClass === Factory::class ? View::class : null;
    }

    /**
     * @param class-string $class
     * @psalm-external-mutation-free
     */
    private static function narrowedUnion(string $class): Union
    {
        return self::$narrowedUnions[$class] ??= new Union([new TNamedObject($class)]);
    }

    /**
     * Sound return for a leading-spread view() call of unknown cardinality: the
     * union of both func_num_args() branches, on the contracts so no concrete-only
     * call is falsely accepted regardless of which branch runs.
     *
     * @psalm-external-mutation-free
     */
    private static function spreadReturn(): Union
    {
        return self::$spreadUnion ??= new Union([
            new TNamedObject(\Illuminate\Contracts\View\Factory::class),
            new TNamedObject(\Illuminate\Contracts\View\View::class),
        ]);
    }

    /**
     * Register for Factory (direct usage), the canonical View facade, plus any
     * root aliases that proxy to it.
     *
     * The canonical facade is hardcoded (not left to FacadeMapProvider) so the
     * missing-view diagnostic still fires on `\Illuminate\Support\Facades\View::make()`
     * in apps that trim their alias registry — otherwise ProducerReturnTypeHandler,
     * which does hardcode that facade, would answer the return type first and this
     * handler's diagnostic would never run. Matches the Auth handlers' convention.
     *
     * @inheritDoc
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return \array_values(\array_unique([
            Factory::class,
            \Illuminate\Support\Facades\View::class,
            ...FacadeMapProvider::getFacadeClasses(Factory::class),
        ]));
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'make') {
            return null;
        }

        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null;
        }

        $viewName = self::extractLiteralStringArg($callArgs[0]);

        if ($viewName === null) {
            return null;
        }

        self::checkViewExists($viewName, $event->getCodeLocation(), $event->getSource()->getSuppressedIssues());

        return null;
    }

    /**
     * Extract a literal string value from a call argument's AST node.
     *
     * Returns null for non-literal arguments — the handler only validates
     * view names it can statically determine from the source code.
     *
     * @psalm-mutation-free
     */
    private static function extractLiteralStringArg(Arg $arg): ?string
    {
        $value = $arg->value;

        if ($value instanceof String_) {
            return $value->value;
        }

        return null;
    }

    /**
     * Check whether the given view name resolves to an existing template file.
     *
     * Skips namespaced views (containing '::') since those are resolved through
     * package-registered paths that the plugin may not know about yet.
     *
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkViewExists(string $viewName, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if (!self::$enabled) {
            return;
        }

        // Skip namespaced views (e.g., 'mail::html.header') — they resolve
        // through package-registered paths we don't track yet
        if (\str_contains($viewName, '::')) {
            return;
        }

        if ($viewName === '') {
            return;
        }

        if (self::viewFileExists($viewName)) {
            return;
        }

        IssueBuffer::accepts(
            new MissingView("View '{$viewName}' not found in any of the registered view paths", $codeLocation),
            $suppressedIssues,
        );
    }

    /**
     * Check if a view file exists in any of the configured view paths.
     *
     * Mirrors Laravel's FileViewFinder::findInPaths() logic:
     * converts dot notation to directory separators, then tries
     * each extension in order.
     */
    private static function viewFileExists(string $viewName): bool
    {
        if (isset(self::$resolvedViews[$viewName])) {
            return self::$resolvedViews[$viewName];
        }

        // Convert dot notation to path: 'emails.welcome' → 'emails/welcome'
        $relativePath = \str_replace('.', \DIRECTORY_SEPARATOR, $viewName);

        foreach (self::$viewPaths as $basePath) {
            foreach (self::$extensions as $extension) {
                if (\file_exists($basePath . \DIRECTORY_SEPARATOR . $relativePath . '.' . $extension)) {
                    self::$resolvedViews[$viewName] = true;

                    return true;
                }
            }
        }

        self::$resolvedViews[$viewName] = false;

        return false;
    }
}
