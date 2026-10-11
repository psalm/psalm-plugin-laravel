<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Ai;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Issue\TaintedLlmPrompt;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParamClass;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Suppresses `TaintedLlmPrompt` on a `prompt()`/`stream()` call whose receiver declares agent
 * middleware containing a guard whose `handle()` is annotated `@psalm-taint-escape llm_prompt`.
 * Nothing here names a guard package: any library or app-local guard opts in with that one line.
 * Recipe: `docs/security.md`.
 *
 * Trust model: a declaration, not a proof. The middleware list is read from `middleware()`'s
 * DECLARED return type, never its body, and the receiver and guard are bounds checked against every
 * analysed subclass (not against subclasses outside the project). Closure entries carry no
 * annotatable method and contribute nothing ({@see middlewareCandidates()}).
 *
 * Only `handle()` is read: laravel/ai 1.x dispatches `handle()` on every non-Closure middleware
 * entry, never `__invoke()` (`vendor/laravel/ai/src/Gateway/TextGenerationLoop.php`, `runStep()`).
 *
 * An agent that remembers conversations is never exempted: its title-generation call re-sends the
 * raw prompt to the model with no agent middleware, so the guard does not cover that second call.
 *
 * Every gate misses toward `null` (finding retained). Applied at issue emission, never by editing
 * the taint graph (`docs/contributing/decisions.md`, "Call-site sink exemptions..."); stateless, so
 * no `reset()` registration.
 *
 * @psalm-api
 */
final class PromptGuardTaintHandler implements BeforeAddIssueInterface
{
    /** `queue()`/`broadcast*()` share the pipeline but are deliberately left reporting. */
    private const SINK_CALL_LABEL_PATTERN = '/^call to (.+)::(prompt|stream)$/i';

    // Lowercased, matching Psalm's storage key spelling.
    private const PROMPTABLE_TRAIT = 'laravel\ai\promptable';

    private const HAS_MIDDLEWARE_INTERFACE = 'laravel\ai\contracts\hasmiddleware';

    private const REMEMBERS_CONVERSATIONS_INTERFACE = 'laravel\ai\contracts\remembersconversations';

    private const REMEMBERS_CONVERSATIONS_TRAIT = 'laravel\ai\concerns\remembersconversations';

    /**
     * Reads storage only (see the class docblock).
     *
     * @psalm-mutation-free
     */
    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $issue = $event->getIssue();

        if (!$issue instanceof TaintedLlmPrompt) {
            return null;
        }

        $sink = self::sinkCall($issue->journey);

        if ($sink === null) {
            return null;
        }

        $codebase = $event->getCodebase();
        $receiver = self::classStorage($codebase, $sink['class']);

        if (!$receiver instanceof ClassLikeStorage
            || !isset($receiver->class_implements[self::HAS_MIDDLEWARE_INTERFACE])
        ) {
            return null;
        }

        // The label alone proves nothing: a userland class can self-declare a `prompt()` sink and
        // borrow an unrelated guard stack.
        $sinkId = $receiver->declaring_method_ids[$sink['method']] ?? null;

        if ($sinkId === null || !\str_starts_with(\strtolower((string) $sinkId), self::PROMPTABLE_TRAIT . '::')) {
            return null;
        }

        // The call dispatches on the runtime object, not the static receiver type, so every analysed
        // subclass must agree. An unreadable subclass declines.
        $family = self::withDescendants($codebase, $receiver);
        $middlewareId = $receiver->declaring_method_ids['middleware'] ?? null;

        if ($family === null || $middlewareId === null) {
            return null;
        }

        foreach ($family as $class) {
            // A subclass swapping `middleware()` (also via a trait import, which leaves
            // `MethodStorage::$overridden_downstream` unset) would inherit the base's exemption.
            $declared = $class->declaring_method_ids['middleware'] ?? null;

            if ($declared === null || (string) $declared !== (string) $middlewareId) {
                return null;
            }

            if (self::remembersConversations($codebase, $class)) {
                return null;
            }
        }

        $middleware = self::methodStorage($codebase, $receiver, 'middleware');

        if (!$middleware instanceof MethodStorage || !$middleware->return_type instanceof Union) {
            return null;
        }

        foreach (self::middlewareCandidates($middleware->return_type) as $candidate) {
            if (self::guardEscapesEverywhere($codebase, $candidate)) {
                return false;
            }
        }

        return null;
    }

    /**
     * Mirrors `RememberConversation::appliesTo()`: the contract, or the trait via
     * `class_uses_recursive()`. Psalm does not merge `used_traits` from parents or nested traits,
     * so they are walked here. Unreadable storage counts as remembering.
     *
     * @psalm-mutation-free
     */
    private static function remembersConversations(Codebase $codebase, ClassLikeStorage $storage): bool
    {
        if (isset($storage->class_implements[self::REMEMBERS_CONVERSATIONS_INTERFACE])) {
            return true;
        }

        $pending = [$storage];

        foreach (\array_keys($storage->parent_classes) as $parentLc) {
            $parent = self::classStorage($codebase, $parentLc);

            if (!$parent instanceof ClassLikeStorage) {
                return true;
            }

            $pending[] = $parent;
        }

        $visited = [];

        while ($pending !== []) {
            $current = \array_pop($pending);

            foreach (\array_keys($current->used_traits) as $traitLc) {
                if ($traitLc === self::REMEMBERS_CONVERSATIONS_TRAIT) {
                    return true;
                }

                if (isset($visited[$traitLc])) {
                    continue;
                }

                $visited[$traitLc] = true;
                $trait = self::classStorage($codebase, $traitLc);

                if (!$trait instanceof ClassLikeStorage) {
                    return true;
                }

                $pending[] = $trait;
            }
        }

        return false;
    }

    /**
     * @param list<array{location: ?\Psalm\CodeLocation, label: string, entry_path_type: string}> $journey
     *
     * @return array{class: string, method: lowercase-string}|null
     *
     * @psalm-pure
     */
    private static function sinkCall(array $journey): ?array
    {
        $tail = $journey === [] ? null : $journey[\count($journey) - 1];

        if ($tail === null || \preg_match(self::SINK_CALL_LABEL_PATTERN, $tail['label'], $matches) !== 1) {
            return null;
        }

        // `<class>` is the call's receiver, not the declaring trait, so an interface or union
        // receiver labels the interface and fails the `HasMiddleware`/`Promptable` checks.
        return ['class' => $matches[1], 'method' => \strtolower($matches[2])];
    }

    /**
     * An unpopulated or unseen classlike is "not proven", not a throw.
     *
     * @psalm-mutation-free
     */
    private static function classStorage(Codebase $codebase, string $className): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
    }

    /**
     * Resolves via `declaring_method_ids` (inherited declarations included); `$return_type` is the
     * declared signature, never an inferred type.
     *
     * @psalm-mutation-free
     */
    private static function methodStorage(Codebase $codebase, ClassLikeStorage $storage, string $methodName): ?MethodStorage
    {
        $methodId = $storage->declaring_method_ids[$methodName] ?? null;

        if ($methodId === null) {
            return null;
        }

        try {
            return $codebase->methods->getStorage($methodId);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * `$storage` followed by every analysed classlike below it, or null if one is unreadable.
     * `ClassLikeStorage::$dependent_classlikes` is not transitively closed (`A < B < C < D`: `A`
     * lists `B` and `C`, never `D`), hence the worklist walk.
     *
     * @return non-empty-list<ClassLikeStorage>|null
     *
     * @psalm-mutation-free
     */
    private static function withDescendants(Codebase $codebase, ClassLikeStorage $storage): ?array
    {
        $found = [];
        $queue = \array_keys($storage->dependent_classlikes);

        while ($queue !== []) {
            $name = \array_pop($queue);

            if (isset($found[$name])) {
                continue;
            }

            $descendant = self::classStorage($codebase, $name);

            if (!$descendant instanceof ClassLikeStorage) {
                return null;
            }

            $found[$name] = $descendant;

            foreach (\array_keys($descendant->dependent_classlikes) as $next) {
                if (!isset($found[$next])) {
                    $queue[] = $next;
                }
            }
        }

        return [$storage, ...\array_values($found)];
    }

    /**
     * Guard class names from the VALUE position of `middleware()`'s declared array type.
     * `list<Guard>`/`array<int, Guard>` (object entry) and `class-string<Guard>`/`Guard::class`
     * (container-resolved entry) both end up in `handle()`, so the form is not tracked.
     *
     * Closure entries contribute nothing; pinned by
     * `PromptGuardClosureMiddlewareKnownLimitation.phpt`.
     *
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    private static function middlewareCandidates(Union $type): array
    {
        $candidates = [];

        foreach ($type->getAtomicTypes() as $atomic) {
            $values = match (true) {
                $atomic instanceof TKeyedArray => $atomic->getGenericValueType(),
                $atomic instanceof TArray => $atomic->type_params[1],
                default => null,
            };

            if ($values === null) {
                continue;
            }

            foreach ($values->getAtomicTypes() as $value) {
                // TClosure extends TNamedObject and TTemplateParamClass extends TClassString;
                // skip both explicitly or they would be misread as guards.
                if ($value instanceof TClosure || $value instanceof TTemplateParamClass) {
                    continue;
                }

                if ($value instanceof TNamedObject || $value instanceof TLiteralClassString) {
                    $candidates[] = $value->value;

                    continue;
                }

                // Bare `class-string` has a null `$as_type` and names no candidate.
                if ($value instanceof TClassString
                    && $value->as_type instanceof TNamedObject
                    && !$value->as_type instanceof TClosure
                ) {
                    $candidates[] = $value->as_type->value;
                }
            }
        }

        return $candidates;
    }

    /**
     * True when the guard and every analysed subclass carries the escape on `handle()`. The declared
     * `list<Guard>` is a bound that any subclass satisfies, including one overriding `handle()`
     * without the escape.
     *
     * @psalm-mutation-free
     */
    private static function guardEscapesEverywhere(Codebase $codebase, string $guardName): bool
    {
        $guard = self::classStorage($codebase, $guardName);
        $family = $guard instanceof ClassLikeStorage ? self::withDescendants($codebase, $guard) : null;

        if ($family === null) {
            return false;
        }

        foreach ($family as $class) {
            $handle = self::methodStorage($codebase, $class, 'handle');

            if (!$handle instanceof MethodStorage || ($handle->removed_taints & TaintKind::INPUT_LLM_PROMPT) === 0) {
                return false;
            }
        }

        return true;
    }
}
