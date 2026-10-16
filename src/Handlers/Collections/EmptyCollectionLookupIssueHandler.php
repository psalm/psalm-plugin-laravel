<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Issue\ArgumentIssue;
use Psalm\Issue\ArgumentTypeCoercion;
use Psalm\Issue\InvalidArgument;
use Psalm\Issue\InvalidScalarArgument;
use Psalm\Issue\PossiblyInvalidArgument;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
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
 * The issue is dropped only when the expected type quoted in its message is exactly the declaring
 * method's declared parameter with every class template replaced by `never`. A populated atomic of
 * the same receiver union quotes its own substituted type and keeps reporting. Every gate misses
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

        // "Argument 1 of Illuminate\Support\Collection::get expects never, but string provided"
        // (Psalm 7 rc2 builds all four classes' messages from `$param_type->getId()` this way).
        if (\preg_match('/^Argument (\d+) of \S+ expects (.+?), but /s', $issue->message, $matches) !== 1) {
            return null;
        }

        $expected = self::declaredParamWithEmptyReceiver(
            $event->getCodebase(),
            $class,
            $method,
            (int) $matches[1] - 1,
        );

        return $expected === $matches[2] ? false : null;
    }

    /**
     * The declared type of parameter `$offset` with every class template of the declaring class
     * replaced by `never`, as Psalm quotes it. Null when it cannot be proven: unreadable storage, a
     * lookup declared outside Laravel's collections, or a parameter that names no class template
     * (an issue about it says nothing about an empty receiver).
     */
    private static function declaredParamWithEmptyReceiver(
        Codebase $codebase,
        string $class,
        string $method,
        int $offset,
    ): ?string {
        try {
            $storage = self::declaringStorage($codebase, $class, $method);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        if (!$storage instanceof ClassLikeStorage) {
            return null;
        }

        $declared = self::declaredParam($storage, $method, $offset);

        if (!$declared instanceof Union || !$declared->hasTemplate()) {
            return null;
        }

        $never = Type::getNever();
        $bounds = [];

        foreach ($storage->template_types ?? [] as $name => $definingClasses) {
            foreach (\array_keys($definingClasses) as $definingClass) {
                $bounds[$name][$definingClass] = $never;
            }
        }

        return TemplateInferredTypeReplacer::replace($declared, new TemplateResult([], $bounds), $codebase)->getId();
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

    /**
     * @psalm-mutation-free
     */
    private static function declaredParam(ClassLikeStorage $storage, string $method, int $offset): ?Union
    {
        return ($storage->methods[$method] ?? null)?->params[$offset]->type ?? null;
    }
}
