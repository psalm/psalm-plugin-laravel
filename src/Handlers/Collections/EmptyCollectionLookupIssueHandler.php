<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\TypeVisitor\TemplateTypeCollector;
use Psalm\Issue\ArgumentIssue;
use Psalm\Issue\ArgumentTypeCoercion;
use Psalm\Issue\InvalidArgument;
use Psalm\Issue\InvalidScalarArgument;
use Psalm\Issue\PossiblyInvalidArgument;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Drops the argument issues Psalm raises when a read-only lookup (`get`, `has`, `contains`, ...) is
 * called on an EMPTY collection (`Collection<never, never>`, from bare `collect()`, `Collection::empty()`,
 * or one side of a ternary / if-else merge).
 *
 * Psalm checks arguments per receiver atomic with the class templates substituted, so the empty atomic
 * expects `never` (`array<array-key, never>`, `callable(never, never): bool|string`, ...) and rejects any
 * argument, although looking something up in an empty collection is valid and just returns the default.
 * No stub can express "any key when TKey is never" without also accepting wrong keys on populated
 * collections (decisions.md, "Lookups on an empty collection"), so the issue is dropped at emission.
 *
 * The issue is dropped only when the expected type quoted in its message is exactly a declared parameter
 * of the declaring method with every class template replaced by `never` (every parameter is tried, because
 * named arguments shift Psalm's positional numbering), AND the provided type fits that parameter with each
 * template replaced by its own upper bound (`array_key_exists(new stdClass)` throws even on an empty
 * collection), AND no class template of that parameter hides in the quoted string behind another top-level
 * atomic (`TValue|null` reads `null` for an empty and for a `Collection<int, null>` receiver alike). A populated atomic of the same receiver union quotes its own substituted type and keeps
 * reporting. Every gate misses
 * toward `null` (issue retained).
 *
 * Stateless, so no `reset()` registration.
 *
 * @psalm-api
 */
final class EmptyCollectionLookupIssueHandler implements BeforeAddIssueInterface
{
    /**
     * Classes whose own declaration of a lookup is Laravel's (lowercased). The method id Psalm quotes
     * names the receiver's class, so the declaring method is resolved from storage: a subclass that
     * redeclares a lookup resolves to itself and is declined.
     */
    private const DECLARING_CLASSES = [
        'illuminate\support\collection' => true,
        'illuminate\database\eloquent\collection' => true,
    ];

    private const ARGUMENT_MISMATCH_ISSUES = [
        InvalidArgument::class => true,
        PossiblyInvalidArgument::class => true,
        ArgumentTypeCoercion::class => true,
        InvalidScalarArgument::class => true,
    ];

    /** Lowercased, matching the issue's `$function_id`. */
    private const LOOKUP_METHODS = [
        'get' => true,
        'has' => true,
        'hasany' => true,
        'contains' => true,
        'containsstrict' => true,
        'doesntcontain' => true,
        'doesntcontainstrict' => true,
        'search' => true,
        'offsetget' => true,
        'offsetexists' => true,
    ];

    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $issue = $event->getIssue();

        // The classes Psalm's ArgumentAnalyzer picks between for a failed type match. NullArgument
        // and PossiblyNullArgument carry a different message and are not needed: `get()` declares
        // `TKey|null`, which is `null` for a never key.
        if (!$issue instanceof ArgumentIssue) {
            return null;
        }

        if (!isset(self::ARGUMENT_MISMATCH_ISSUES[$issue::class])) {
            return null;
        }

        $functionId = $issue->function_id;

        if ($functionId === null) {
            return null;
        }

        $separator = \strpos($functionId, '::');

        if ($separator === false) {
            return null;
        }

        $class = \substr($functionId, 0, $separator);
        $method = \substr($functionId, $separator + 2);

        if (!isset(self::LOOKUP_METHODS[$method])) {
            return null;
        }

        // "Argument 1 of Illuminate\Support\Collection::get expects never, but string provided". Psalm 7 rc2
        // builds all four classes' messages from `getId()` this way; the provided type may carry a
        // `parent type ` / `possibly different type ` / `possibly undefined ` prefix. Anything else (e.g. the
        // "with additional array shape fields" tail) does not match and keeps the issue.
        if (\preg_match(
            '/^Argument \d+ of \S+ expects (.+?), but (?:parent type |possibly different type |possibly undefined )?(.+) provided$/s',
            $issue->message,
            $matches,
        ) !== 1) {
            return null;
        }

        $codebase = $event->getCodebase();

        try {
            $storage = self::declaringStorage($codebase, $class, $method);
            $provided = Type::parseString($matches[2]);
        } catch (\Throwable) {
            // Unreadable storage or a provided type Psalm's parser cannot read back proves nothing.
            return null;
        }

        // `getId()` does not escape quotes (`'it's'|stdClass`), so the parser can read a different type
        // back; trust it only when it round-trips losslessly.
        if (!$storage instanceof ClassLikeStorage || $provided->getId() !== $matches[2]) {
            return null;
        }

        return self::isEmptyReceiverMismatch($codebase, $storage, $method, $matches[1], $provided) ? false : null;
    }

    /**
     * True when some parameter of the declaring method, with every class template replaced by `never`,
     * is exactly the expected type the issue quotes (the receiver is empty), and the provided type is
     * valid for that parameter's own bounds. Psalm numbers `Argument N` by call-site position, so a named
     * call (`get(default: 'x', key: $k)`) can compare against another parameter: every parameter naming a
     * class template is tried, and the provided type is checked against the same parameter it matched.
     *
     * Emptiness proves no entries exist, not that the argument is usable: `array_key_exists()` still
     * throws a TypeError for `new \stdClass()`, so the provided type must also fit the declared parameter
     * with each template replaced by its own upper bound (`TKey` -> `array-key`, `TValue` -> `mixed`,
     * `TModel` -> `Model`).
     */
    private static function isEmptyReceiverMismatch(
        Codebase $codebase,
        ClassLikeStorage $storage,
        string $method,
        string $expected,
        Union $provided,
    ): bool {
        $never = Type::getNever();
        $neverBounds = [];
        $upperBounds = [];

        foreach ($storage->template_types ?? [] as $name => $definingClasses) {
            foreach ($definingClasses as $definingClass => $upperBound) {
                $neverBounds[$name][$definingClass] = $never;
                $upperBounds[$name][$definingClass] = $upperBound;
            }
        }

        foreach (($storage->methods[$method] ?? null)?->params ?? [] as $param) {
            $declared = $param->type;

            if (!$declared instanceof Union || !$declared->hasTemplate()) {
                continue;
            }

            $withNever = TemplateInferredTypeReplacer::replace($declared, new TemplateResult([], $neverBounds), $codebase);

            // Unchanged: the parameter names no class template (only method templates), so an issue
            // about it says nothing about an empty receiver.
            if ($withNever->getId() === $declared->getId() || $withNever->getId() !== $expected) {
                continue;
            }

            if (self::isAmbiguousWithPopulatedReceiver($codebase, $declared, $upperBounds)) {
                continue;
            }

            $valid = TemplateInferredTypeReplacer::replace($declared, new TemplateResult([], $upperBounds), $codebase);

            if (UnionTypeComparator::isContainedBy($codebase, $provided, $valid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A class template that occurs ONLY as a top-level atomic of the parameter leaves no trace of `never` in
     * the quoted string when another top-level atomic absorbs its instantiation: `TValue|null` quotes `null`
     * for `TValue = never` and for `TValue = null` alike. When those other atomics can overlap the template's
     * upper bound, the quoted type no longer proves the receiver is empty. A template that also occurs nested
     * (`array<array-key, TKey>`, `callable(TValue): bool`) keeps `never` visible, so it proves emptiness.
     *
     * @param array<string, array<string, Union>> $upperBounds
     */
    private static function isAmbiguousWithPopulatedReceiver(Codebase $codebase, Union $declared, array $upperBounds): bool
    {
        foreach ($declared->getAtomicTypes() as $key => $atomic) {
            if (!$atomic instanceof TTemplateParam || !isset($upperBounds[$atomic->param_name][$atomic->defining_class])) {
                continue;
            }

            $others = $declared->getAtomicTypes();
            unset($others[$key]);

            if ($others === []) {
                continue;
            }

            $rest = new Union(\array_values($others));
            $collector = new TemplateTypeCollector();
            $collector->traverse($rest);

            foreach ($collector->getTemplateTypes() as $nested) {
                if ($nested->param_name === $atomic->param_name && $nested->defining_class === $atomic->defining_class) {
                    // Also nested somewhere: `never` stays visible.
                    continue 2;
                }
            }

            if (UnionTypeComparator::canExpressionTypesBeIdentical(
                $codebase,
                $rest,
                $upperBounds[$atomic->param_name][$atomic->defining_class],
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Storage of the class declaring `$method` for the receiver class `$class`, when it is one of
     * Laravel's collections.
     *
     * @psalm-capabilities read-props
     *
     * @throws \InvalidArgumentException
     * @throws UnpopulatedClasslikeException
     */
    private static function declaringStorage(Codebase $codebase, string $class, string $method): ?ClassLikeStorage
    {
        $provider = $codebase->classlike_storage_provider;
        $declaring = $provider->get($class)->declaring_method_ids[$method] ?? null;

        if ($declaring === null) {
            return null;
        }

        $declaringClass = $declaring->fq_class_name;

        return isset(self::DECLARING_CLASSES[\strtolower($declaringClass)]) ? $provider->get($declaringClass) : null;
    }
}
