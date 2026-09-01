<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\Mail\Mailables\Content;
use Illuminate\View\Factory;
use Illuminate\View\View;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\MissingView;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Detects a view name that does not correspond to an existing template file,
 * across every Laravel API that accepts one — the view() helper, Factory
 * (make/first/renderWhen/renderUnless/renderEach/composer/creator) and its View facade,
 * ResponseFactory::view() (concrete, contract, and Response facade),
 * Router::view(), MailMessage::view()/markdown(), Mailable::view()/markdown()/text(),
 * Mailables\Content's constructor, and TestResponse::assertViewIs() — and narrows
 * the view() helper's return type past the stub's contract fallback to a concrete class.
 *
 * The receiver classes for the method-call families are {@see ViewNameSignatures},
 * which also resolves a receiver back to a role so this handler knows which
 * argument position(s) hold a view name — `view()` alone means two different
 * positions depending on receiver (ResponseFactory arg 0, Router arg 1).
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
final class MissingViewHandler implements AfterExpressionAnalysisInterface, FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
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
     * The receiver classes for every view-name-bearing method call family — see
     * {@see ViewNameSignatures} for the table and its own hardcode-vs-FacadeMapProvider
     * rationale (mirrors the Auth handlers' convention of hardcoding a canonical
     * facade so an app that trims its alias registry still gets the diagnostic).
     *
     * @inheritDoc
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return ViewNameSignatures::getClassLikeNames();
    }

    /**
     * Dispatches by (role, method name lowercase) to the family-specific check
     * below, then unconditionally returns null: this provider only emits the
     * MissingView diagnostic as a side effect, never a type. A non-null Union
     * here on the pseudo-method (facade) path would fatal Psalm's
     * getMethodParams() — see ProducerReturnTypeHandler's docblock.
     *
     * @inheritDoc
     */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $role = ViewNameSignatures::resolveRole($event->getFqClasslikeName());

        if ($role === null) {
            return null;
        }

        $methodNameLower = $event->getMethodNameLowercase();
        $callArgs = $event->getCallArgs();
        $codeLocation = $event->getCodeLocation();
        $suppressedIssues = $event->getSource()->getSuppressedIssues();

        match ($role) {
            ViewNameSignatures::ROLE_VIEW_FACTORY => self::checkViewFactoryCall($methodNameLower, $callArgs, $codeLocation, $suppressedIssues),
            ViewNameSignatures::ROLE_RESPONSE_FACTORY => self::checkResponseFactoryCall($methodNameLower, $callArgs, $codeLocation, $suppressedIssues),
            ViewNameSignatures::ROLE_ROUTER => self::checkRouterCall($methodNameLower, $callArgs, $codeLocation, $suppressedIssues),
            ViewNameSignatures::ROLE_MAIL_MESSAGE => self::checkMailMessageCall($methodNameLower, $callArgs, $codeLocation, $suppressedIssues),
            ViewNameSignatures::ROLE_MAILABLE => self::checkMailableCall($methodNameLower, $callArgs, $codeLocation, $suppressedIssues),
            ViewNameSignatures::ROLE_TEST_RESPONSE => self::checkTestResponseCall($methodNameLower, $callArgs, $codeLocation, $suppressedIssues),
        };

        return null;
    }

    /**
     * Mailables\Content carries four view-name constructor arguments. This is a
     * syntax-level hook because constructors do not participate in method
     * return-type provider dispatch. The separate $htmlString argument is raw
     * rendered HTML and must never be treated as a template name.
     */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if (!$expr instanceof New_ || !$expr->class instanceof Name || $expr->isFirstClassCallable()) {
            return null;
        }

        /** @psalm-var ?string $resolvedName */
        $resolvedName = $expr->class->getAttribute('resolvedName');
        $className = $resolvedName ?? $expr->class->toString();
        if (\strcasecmp($className, Content::class) !== 0) {
            return null;
        }

        $callArgs = \array_values($expr->getArgs());
        $source = $event->getStatementsSource();
        $codeLocation = new CodeLocation($source, $expr);
        $suppressedIssues = $source->getSuppressedIssues();

        self::checkArgViewName($callArgs, 0, 'view', $codeLocation, $suppressedIssues);
        self::checkArgViewName($callArgs, 1, 'html', $codeLocation, $suppressedIssues);
        self::checkArgViewName($callArgs, 2, 'text', $codeLocation, $suppressedIssues);
        self::checkArgViewName($callArgs, 3, 'markdown', $codeLocation, $suppressedIssues);

        return null;
    }

    /**
     * Factory::make()/first()/renderWhen()/renderUnless()/renderEach() and their
     * View-facade forms. Semantics read from Illuminate\View\Factory's own body,
     * not its PHPDoc:
     *  - make(): single view name at $view (position 0).
     *  - first(): `Arr::first($views, fn => exists($view))` throws only when NONE
     *    of $views exist — see checkAllMissingInArray().
     *  - renderWhen()/renderUnless(): single view name at $view (position 1).
     *  - renderEach(): a view name at $view (position 0) rendered per data item,
     *    AND an "empty" view at $empty (position 3) rendered when $data is empty —
     *    unless $empty starts with 'raw|', which is raw text, not a view name.
     *
     * @param list<Arg> $callArgs
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkViewFactoryCall(string $methodNameLower, array $callArgs, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        switch ($methodNameLower) {
            case 'make':
                self::checkArgViewName($callArgs, 0, 'view', $codeLocation, $suppressedIssues);

                break;

            case 'first':
                $arg = self::argByNameOrPosition($callArgs, 0, 'views');

                if ($arg instanceof \PhpParser\Node\Arg) {
                    $viewNames = self::extractLiteralStringArrayArg($arg);

                    if ($viewNames !== null) {
                        self::checkAllMissingInArray($viewNames, $codeLocation, $suppressedIssues);
                    }
                }

                break;

            case 'renderwhen':
            case 'renderunless':
                self::checkArgViewName($callArgs, 1, 'view', $codeLocation, $suppressedIssues);

                break;

            case 'rendereach':
                self::checkArgViewName($callArgs, 0, 'view', $codeLocation, $suppressedIssues);

                $emptyArg = self::argByNameOrPosition($callArgs, 3, 'empty');

                if ($emptyArg instanceof \PhpParser\Node\Arg) {
                    $emptyName = self::extractLiteralStringArg($emptyArg);

                    if ($emptyName !== null && !\str_starts_with($emptyName, 'raw|')) {
                        self::checkViewExists($emptyName, $codeLocation, $suppressedIssues);
                    }
                }

                break;

            case 'composer':
            case 'creator':
                self::checkViewPatternArg($callArgs, $codeLocation, $suppressedIssues);

                break;
        }
    }

    /**
     * Factory::composer()/creator() accept one view name or a list. Each entry
     * is registered independently, unlike first()'s fallback semantics, so a
     * missing literal is reported even when another entry exists. Wildcards
     * are event patterns rather than concrete templates and remain unchecked.
     *
     * @param list<Arg> $callArgs
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkViewPatternArg(array $callArgs, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        $arg = self::argByNameOrPosition($callArgs, 0, 'views');
        if (!$arg instanceof Arg) {
            return;
        }

        if ($arg->value instanceof Array_) {
            $viewNames = self::extractLiteralStringArrayArg($arg);
            if ($viewNames === null) {
                return;
            }

            foreach ($viewNames as $viewName) {
                self::checkConcreteViewPattern($viewName, $codeLocation, $suppressedIssues);
            }

            return;
        }

        $viewName = self::extractLiteralStringArg($arg);
        if ($viewName !== null) {
            self::checkConcreteViewPattern($viewName, $codeLocation, $suppressedIssues);
        }
    }

    /** @param array<array-key, string> $suppressedIssues */
    private static function checkConcreteViewPattern(string $viewName, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if (\str_contains($viewName, '*')) {
            return;
        }

        self::checkViewExists($viewName, $codeLocation, $suppressedIssues);
    }

    /**
     * ResponseFactory::view() (concrete, contract, and Response facade forms).
     * `is_array($view)` forwards to Factory::first() semantics (a candidate
     * list); otherwise it forwards to Factory::make() semantics (one name).
     *
     * @param list<Arg> $callArgs
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkResponseFactoryCall(string $methodNameLower, array $callArgs, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if ($methodNameLower !== 'view') {
            return;
        }

        $arg = self::argByNameOrPosition($callArgs, 0, 'view');

        if (!$arg instanceof \PhpParser\Node\Arg) {
            return;
        }

        if ($arg->value instanceof Array_) {
            $viewNames = self::extractLiteralStringArrayArg($arg);

            if ($viewNames !== null) {
                self::checkAllMissingInArray($viewNames, $codeLocation, $suppressedIssues);
            }

            return;
        }

        $viewName = self::extractLiteralStringArg($arg);

        if ($viewName !== null) {
            self::checkViewExists($viewName, $codeLocation, $suppressedIssues);
        }
    }

    /**
     * Router::view()/Route facade — the view name is $view at position 1
     * (`view($uri, $view, ...)`), not position 0 like every other family here.
     *
     * @param list<Arg> $callArgs
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkRouterCall(string $methodNameLower, array $callArgs, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if ($methodNameLower !== 'view') {
            return;
        }

        self::checkArgViewName($callArgs, 1, 'view', $codeLocation, $suppressedIssues);
    }

    /**
     * MailMessage::view()/markdown() — both take a single view name at $view
     * (position 0); markdown() renders through Illuminate\Mail\Markdown, which
     * resolves non-namespaced names against the same registered view paths.
     *
     * @param list<Arg> $callArgs
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkMailMessageCall(string $methodNameLower, array $callArgs, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if ($methodNameLower !== 'view' && $methodNameLower !== 'markdown') {
            return;
        }

        self::checkArgViewName($callArgs, 0, 'view', $codeLocation, $suppressedIssues);
    }

    /**
     * Mailable::view()/markdown() take $view while text() calls the equivalent
     * parameter $textView. All three are resolved through the same view finder.
     *
     * @param list<Arg> $callArgs
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkMailableCall(string $methodNameLower, array $callArgs, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if ($methodNameLower === 'view' || $methodNameLower === 'markdown') {
            self::checkArgViewName($callArgs, 0, 'view', $codeLocation, $suppressedIssues);

            return;
        }

        if ($methodNameLower === 'text') {
            self::checkArgViewName($callArgs, 0, 'textview', $codeLocation, $suppressedIssues);
        }
    }

    /**
     * TestResponse::assertViewIs() compares $value against the rendered view's
     * name — a view name at position 0.
     *
     * @param list<Arg> $callArgs
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkTestResponseCall(string $methodNameLower, array $callArgs, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if ($methodNameLower !== 'assertviewis') {
            return;
        }

        self::checkArgViewName($callArgs, 0, 'value', $codeLocation, $suppressedIssues);
    }

    /**
     * Resolve one argument by name-or-position, extract a literal string, and
     * run the existence check — the single-view-name shape shared by most
     * families here.
     *
     * @param list<Arg> $callArgs
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkArgViewName(
        array $callArgs,
        int $position,
        string $paramName,
        CodeLocation $codeLocation,
        array $suppressedIssues,
    ): void {
        $arg = self::argByNameOrPosition($callArgs, $position, $paramName);

        if (!$arg instanceof \PhpParser\Node\Arg) {
            return;
        }

        $viewName = self::extractLiteralStringArg($arg);

        if ($viewName !== null) {
            self::checkViewExists($viewName, $codeLocation, $suppressedIssues);
        }
    }

    /**
     * Resolve one parameter's Arg node whether the call used positional or
     * named arguments. PHP requires every positional argument to precede any
     * named one, so a non-named node already at `$position` is authoritative;
     * otherwise the parameter can only have been supplied by name, in whatever
     * order — e.g. `Route::view(view: 'welcome', uri: '/x')` must not read
     * '/x' (position 1) as the view name just because $paramName's usual slot
     * is 1.
     *
     * @param list<Arg> $callArgs
     */
    private static function argByNameOrPosition(array $callArgs, int $position, string $paramName): ?Arg
    {
        if (isset($callArgs[$position]) && $callArgs[$position]->name === null) {
            return $callArgs[$position];
        }

        foreach ($callArgs as $arg) {
            if ($arg->name !== null && $arg->name->toLowerString() === $paramName) {
                return $arg;
            }
        }

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
     * Extract a literal list<string> from an array-literal argument.
     *
     * Any non-literal element, a keyed or spread element, or a non-array-literal
     * argument bails to null — the caller then skips the check entirely rather
     * than validate a partial or guessed candidate list.
     *
     * @return list<string>|null
     * @psalm-mutation-free
     */
    private static function extractLiteralStringArrayArg(Arg $arg): ?array
    {
        if (!$arg->value instanceof Array_) {
            return null;
        }

        $viewNames = [];

        foreach ($arg->value->items as $item) {
            if ($item === null || $item->unpack || $item->key !== null || !$item->value instanceof String_) {
                return null;
            }

            $viewNames[] = $item->value->value;
        }

        return $viewNames;
    }

    /**
     * Check a candidate list the way Factory::first()/ResponseFactory::view($array)
     * do: `Arr::first($views, fn => exists($view))` only throws when NONE of the
     * candidates exist, so this only emits when every one of them is missing.
     *
     * A namespaced or empty candidate is skipped by checkViewExists() rather than
     * counted as "missing" — since we can't tell if it resolves, the whole array
     * is left unchecked (bail) rather than risk a false "none of these exist".
     *
     * @param list<string> $viewNames
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkAllMissingInArray(array $viewNames, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if (!self::$enabled || $viewNames === []) {
            return;
        }

        foreach ($viewNames as $viewName) {
            if ($viewName === '' || \str_contains($viewName, '::') || self::viewFileExists($viewName)) {
                return;
            }
        }

        $quoted = \implode(', ', \array_map(static fn(string $viewName): string => "'{$viewName}'", $viewNames));

        IssueBuffer::accepts(
            new MissingView("None of the views {$quoted} were found in any of the registered view paths", $codeLocation),
            $suppressedIssues,
        );
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
