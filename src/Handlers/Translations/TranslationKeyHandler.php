<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Translations;

use Illuminate\Translation\Translator;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\LaravelPlugin\Issues\MissingTranslation;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNonEmptyArray;
use Psalm\Type\Union;

/**
 * Resolves return types for __() and trans() translation helpers, and for
 * Translator::get() called directly on the concrete class (the shape Blade's
 * @lang compiles to — see CompilesTranslations::compileLang()).
 *
 * For literal string keys, uses Laravel's Translator to determine
 * whether the key exists and returns a precise type (non-empty-string,
 * non-empty-array, or string for missing keys). Optionally emits
 * MissingTranslation issues for keys not found in language files
 * (function surface only — see below).
 *
 * For dynamic keys (variables, sprintf, concatenation), returns string
 * as a safe fallback — avoids the string|array union that causes
 * widespread PossiblyInvalidCast noise in real projects.
 *
 * Namespaced package keys (e.g., 'package::file.key') are skipped
 * since packages may not have their translations published.
 *
 * A bare `trans()` call (no args) hits Laravel's `is_null($key)` branch, which
 * returns `app('translator')` — the very binding this handler captured at
 * plugin boot (see init()). That resolved binding is narrowed to its concrete
 * class rather than left on the `Translator` contract of the vendor docblock's
 * conditional return (trans() is deliberately not stubbed), as long as the
 * codebase Psalm scanned actually declares it (an app could bind a subclass
 * Psalm never scanned). `__()` shares the `is_null($key)` structure, but its
 * null-key branch returns `$key` (null) instead of the translator, so a bare
 * `__()` keeps its stub fallback. `trans(null)` (an explicit null key) also
 * stays on the vendor conditional — only the truly zero-arg call is narrowed.
 *
 * The findMissingTranslations config option controls only whether
 * MissingTranslation issues are emitted — type narrowing is always
 * active when the translator is available.
 *
 * The method surface only registers on the concrete `Illuminate\Translation\Translator`,
 * never on the `Illuminate\Contracts\Translation\Translator` contract: Psalm resolves a
 * method return-type provider against the call's receiver class first, falling back to
 * the declaring class of the resolved method (MethodCallReturnTypeFetcher::fetch()). A
 * contract-typed receiver (e.g. a type-hinted constructor param) resolves `get()` on the
 * contract itself, so it never reaches this provider and stays `mixed` per the vendor
 * contract docblock — see TransNarrowingBoundariesTest.phpt. A Translator subclass that
 * does not override `get()` still narrows (declaring-class fallback); one that does
 * override it does not, since the provider only covers the base class. It also never
 * emits MissingTranslation:
 * that issue documents itself (docs/issues/MissingTranslation.md) as reachable only through
 * __()/trans(), and opt-in users scanning method calls they don't own (e.g. through a DI
 * container) would otherwise see a new, unexpected emission surface.
 *
 * Beyond the class match, the method surface also gates on the RECEIVER EXPRESSION:
 * only `app('translator')->get(...)` / `resolve('translator')->get(...)` (or their
 * `Translator::class` argument form) — the exact shape Blade's `@lang` compiles to —
 * are narrowed ({@see isContainerTranslatorReceiver()}). This handler's static state
 * (`self::$translator`) is captured from the booted singleton at plugin boot; a `new
 * Translator(...)` or any other receiver is a different instance with its own locale
 * and loader, and answering from the booted singleton's lookup state for it would be
 * provenance-blind. A DI-typed variable, property fetch, or method chain all decline
 * and keep the vendor union.
 *
 * @see https://laravel.com/docs/localization
 */
final class TranslationKeyHandler implements FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    private static ?Translator $translator = null;

    /** Whether to emit MissingTranslation issues for keys not found in language files */
    private static bool $reportMissing = false;

    /**
     * Cached translation resolution results.
     *
     * - null value means the key does not exist (missing translation)
     * - Union value means the key exists and has been resolved to a precise type
     *
     * @var array<string, ?Union>
     */
    private static array $resolvedKeys = [];

    /** @var array<class-string, Union> cache of the zero-arg trans() concrete union, keyed by resolved translator class (Psalm 7 unions are immutable) */
    private static array $zeroArgTransUnions = [];

    /**
     * Forget the current app's translator and translation lookup results. The
     * zero-argument unions are keyed solely by concrete class and immutable, so
     * they can safely remain cached between invocations.
     *
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$translator = null;
        self::$reportMissing = false;
        self::$resolvedKeys = [];
    }

    /** @psalm-external-mutation-free */
    public static function init(Translator $translator, bool $reportMissing): void
    {
        self::$translator = $translator;
        self::$reportMissing = $reportMissing;
        self::$resolvedKeys = [];
        self::$zeroArgTransUnions = [];
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['__', 'trans'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return self::resolveZeroArgTransType($event);
        }

        // A LEADING spread hides the argument count: an empty spread runs Laravel's
        // is_null($key) branch (`trans()` -> translator, `__()` -> null), a non-empty
        // one runs the key lookup. Return the sound union of both branches so the
        // zero-arg possibility is not silently dropped (`trans(...$maybeEmpty)` could
        // be the translator at runtime). The lookup side stays `string`, matching the
        // handler's deliberate string-not-array choice for dynamic keys below.
        if ($callArgs[0]->unpack) {
            if ($event->getFunctionId() === 'trans') {
                return Type::combineUnionTypes(
                    new Union([new TNamedObject(\Illuminate\Contracts\Translation\Translator::class)]),
                    Type::getString(),
                );
            }

            return Type::combineUnionTypes(Type::getString(), Type::getNull());
        }

        return self::resolveKeyArgReturnType(
            $callArgs,
            $event->getStatementsSource(),
            $event->getCodeLocation(),
            emitMissingTranslation: true,
        );
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Translator::class];
    }

    /**
     * Narrows `Illuminate\Translation\Translator::get()` — the call Blade's
     * `@lang('key')` / `@lang('key', $replace)` compiles to
     * (`CompilesTranslations::compileLang()`) — using the same literal-key
     * lookup as the __()/trans() function surface. Fixes the `array|string`
     * `PossiblyInvalidArgument` FP on the compiled echo for a resolvable key,
     * without hiding the genuine array case for a key that resolves to a
     * translation group (that becomes a precise `InvalidArgument` instead).
     *
     * Only narrows the container-fetched singleton receiver — see
     * {@see isContainerTranslatorReceiver()} and the class docblock.
     */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'get') {
            return null;
        }

        $stmt = $event->getStmt();

        if (!$stmt instanceof \PhpParser\Node\Expr\MethodCall || !self::isContainerTranslatorReceiver($stmt->var)) {
            return null;
        }

        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null;
        }

        return self::resolveKeyArgReturnType(
            $callArgs,
            $event->getSource(),
            $event->getCodeLocation(),
            emitMissingTranslation: false,
        );
    }

    /**
     * Whether $expr is `app('translator')`, `resolve('translator')`, or either
     * called with `Illuminate\Translation\Translator::class` instead of the string
     * literal — the receiver shape Blade's `@lang` compiles to. No provenance API
     * exists at a return-type provider to trace an arbitrary expression back to the
     * booted singleton, so this AST-level match is a deliberately narrow allowlist:
     * a variable, `new Translator(...)`, a property fetch, or a method chain all
     * decline, even though some of those could resolve to the same instance at
     * runtime.
     *
     * Not annotated mutation-free: `FuncCall::getArgs()` is not, and Psalm rejects
     * the annotation on any caller of a method that lacks it.
     */
    private static function isContainerTranslatorReceiver(\PhpParser\Node\Expr $expr): bool
    {
        if (!$expr instanceof \PhpParser\Node\Expr\FuncCall || !$expr->name instanceof \PhpParser\Node\Name) {
            return false;
        }

        $functionName = \strtolower($expr->name->toString());

        if ($functionName !== 'app' && $functionName !== 'resolve') {
            return false;
        }

        $args = $expr->getArgs();

        if ($args === [] || $args[0]->unpack) {
            return false;
        }

        $firstArgValue = $args[0]->value;

        if ($firstArgValue instanceof String_) {
            return $firstArgValue->value === 'translator';
        }

        return $firstArgValue instanceof \PhpParser\Node\Expr\ClassConstFetch
            && $firstArgValue->class instanceof \PhpParser\Node\Name
            && $firstArgValue->name instanceof \PhpParser\Node\Identifier
            && \strtolower($firstArgValue->name->toString()) === 'class'
            && \ltrim($firstArgValue->class->toString(), '\\') === Translator::class;
    }

    /**
     * Shared literal-key-lookup + string-fallback logic for both the
     * __()/trans() function surface and the Translator::get() method surface.
     *
     * `__()`, `trans()`, and `Translator::get()` all declare the key as their
     * first parameter named `$key`, so a call can put a different argument at
     * position 0 by naming the others ahead of it
     * (`trans(locale: 'en', key: 'x')`) — {@see ArgUtil::byNameOrPosition()}
     * resolves the actual key argument regardless of call order.
     *
     * The literal-key lookup below queries the booted translator's CURRENT default
     * locale and default fallback (`true`) — it never re-runs the lookup for an
     * explicit `$locale` (position 2) or `$fallback` (position 3, `Translator::get()`
     * only). A key that resolves to a string in the default locale can be an array
     * in another one, so a call naming either argument declines entirely, whether
     * or not it also carries a literal key.
     *
     * An unpack ANYWHERE in the argument list (not just a leading one) also declines
     * entirely: `get('key', ...$rest)` has exactly two AST `Arg` nodes (the literal
     * key and the spread), so a locale or fallback `$rest` fills at runtime is
     * invisible to the by-name-or-position checks above — there is no way to tell
     * how many parameters the spread expands into, or with what names.
     *
     * @param list<Arg> $args
     */
    private static function resolveKeyArgReturnType(
        array $args,
        StatementsSource $source,
        CodeLocation $codeLocation,
        bool $emitMissingTranslation,
    ): ?Union {
        foreach ($args as $arg) {
            if ($arg->unpack) {
                return null;
            }
        }

        $keyArg = ArgUtil::byNameOrPosition($args, 0, 'key');

        if (!$keyArg instanceof Arg) {
            return null;
        }

        if (ArgUtil::byNameOrPosition($args, 2, 'locale') instanceof Arg
            || ArgUtil::byNameOrPosition($args, 3, 'fallback') instanceof Arg
        ) {
            return null;
        }

        // Try to resolve literal string keys precisely via the Translator
        $translationKey = self::extractLiteralStringArg($keyArg);

        if ($translationKey !== null) {
            $resolved = self::resolveTranslationType(
                $translationKey,
                $codeLocation,
                $source->getSuppressedIssues(),
                $emitMissingTranslation,
            );

            if ($resolved instanceof \Psalm\Type\Union) {
                // A $replace argument runs the resolved value through Laravel's
                // makeReplacements() interpolation, which can turn a non-empty
                // string into '' (e.g. ':name' replaced with an empty value) —
                // widen to plain string. Group/array results are untouched:
                // interpolation only ever rewrites the string branch.
                if ($resolved->hasString() && !$resolved->hasArray()
                    && ArgUtil::byNameOrPosition($args, 1, 'replace') instanceof Arg
                ) {
                    return Type::getString();
                }

                return $resolved;
            }
        }

        // Dynamic keys (variables, sprintf, concatenation) or missing literal keys:
        // return string to avoid PossiblyInvalidCast noise from string|array union
        $firstArgType = $source->getNodeTypeProvider()->getType($keyArg->value);

        if ($firstArgType instanceof \Psalm\Type\Union) {
            if ($firstArgType->isString()) {
                return Type::getString();
            }

            if ($firstArgType->isNullable() && $firstArgType->hasString()) {
                return Type::combineUnionTypes(Type::getString(), Type::getNull());
            }
        }

        // Non-string args (e.g. __(null)) — handled by stubs
        return null;
    }

    /**
     * Narrow a bare `trans()` call to the concrete resolved Translator class.
     *
     * Only `trans` is eligible — `__()` returns null for a bare call, so its
     * zero-arg form stays on its stub fallback. `Plugin::initTranslationKeyHandler()`
     * already guarantees `self::$translator` is a real `\Illuminate\Translation\Translator`
     * before init() runs, so the narrowed class is the actual resolved concrete
     * (normally `Illuminate\Translation\Translator`, possibly an app subclass).
     */
    private static function resolveZeroArgTransType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getFunctionId() !== 'trans' || !self::$translator instanceof Translator) {
            return null;
        }

        $class = self::$translator::class;

        if (!$event->getStatementsSource()->getCodebase()->classExists($class)) {
            // The app may bind a Translator subclass Psalm never scanned — fall
            // back to the vendor docblock's contract-typed conditional instead
            // of naming an unknown class.
            return null;
        }

        return self::$zeroArgTransUnions[$class] ??= new Union([new TNamedObject($class)]);
    }

    /**
     * Extract a literal string value from a call argument's AST node.
     *
     * Returns null for non-literal arguments — the handler only validates
     * translation keys it can statically determine from the source code.
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
     * Resolve the return type for a translation key, optionally emitting
     * MissingTranslation when the key does not exist and reporting is enabled.
     *
     * Skips namespaced keys (containing '::') since those belong to packages
     * whose translations may not be published to the app's lang/ directory.
     *
     * For existing keys, uses Translator::get() to determine whether the
     * resolved value is a string or an array, returning a precise type.
     *
     * @param array<array-key, string> $suppressedIssues
     */
    private static function resolveTranslationType(
        string $translationKey,
        CodeLocation $codeLocation,
        array $suppressedIssues,
        bool $emitMissingTranslation,
    ): ?Union {
        if (!self::$translator instanceof \Illuminate\Translation\Translator) {
            return null;
        }

        // Skip namespaced package keys (e.g., 'pagination::pages.next') — packages
        // may not have their translations published to the app's lang/ directory
        if (\str_contains($translationKey, '::')) {
            return null;
        }

        if ($translationKey === '') {
            return null;
        }

        $resolvedType = self::resolveKey($translationKey);

        if ($resolvedType instanceof \Psalm\Type\Union) {
            return $resolvedType;
        }

        // Key does not exist — emit the issue only when findMissingTranslations
        // is enabled, then fall through to TransHandler for the fallback type
        if (self::$reportMissing && $emitMissingTranslation) {
            IssueBuffer::accepts(
                new MissingTranslation(
                    "Translation key '{$translationKey}' not found in language files",
                    $codeLocation,
                ),
                $suppressedIssues,
            );
        }

        return null;
    }

    /**
     * Resolve a translation key to a precise Psalm type, caching the result.
     *
     * Uses Translator::has() to check existence, then Translator::get() to
     * determine whether the value is a string or an array.
     *
     * Returns null when the key does not exist. The null sentinel is stored
     * in the cache too, so missing keys are only looked up once.
     */
    private static function resolveKey(string $translationKey): ?Union
    {
        if (\array_key_exists($translationKey, self::$resolvedKeys)) {
            return self::$resolvedKeys[$translationKey];
        }

        // Caller (resolveTranslationType) guarantees $translator is set
        \assert(self::$translator instanceof Translator);

        try {
            $exists = self::$translator->has($translationKey);
        } catch (\Exception|\ParseError) {
            // Malformed language files can cause Translator::has() to throw:
            // - PHP syntax errors → \ParseError (subclass of \Error, not \Exception)
            // - Invalid JSON → \RuntimeException
            // - Custom loaders → other \Exception subclasses
            // Return string|array to avoid emitting a false MissingTranslation
            // for a key that may actually exist.
            $fallback = Type::combineUnionTypes(Type::getString(), Type::getArray());

            return self::$resolvedKeys[$translationKey] = $fallback;
        }

        if (!$exists) {
            self::$resolvedKeys[$translationKey] = null;

            return null;
        }

        try {
            $value = self::$translator->get($translationKey);
        } catch (\Exception|\ParseError) {
            // Key exists but value cannot be retrieved. Same failure modes as
            // has() above (\ParseError for PHP files, \RuntimeException for JSON).
            // Return string|array to avoid false positives, matching TransHandler's fallback.
            $fallback = Type::combineUnionTypes(Type::getString(), Type::getArray());

            return self::$resolvedKeys[$translationKey] = $fallback;
        }

        if (\is_array($value)) {
            $type = $value !== []
                ? new Union([new TNonEmptyArray([Type::getArrayKey(), Type::getMixed()])])
                : Type::getArray();
        } else {
            $type = $value !== '' ? Type::getNonEmptyString() : Type::getString();
        }

        self::$resolvedKeys[$translationKey] = $type;

        return $type;
    }
}
