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
 * Suppresses `TaintedLlmPrompt` on a `prompt()`/`stream()` call whose receiver declares an agent
 * middleware stack containing a guard annotated `@psalm-taint-escape llm_prompt` on its dispatched
 * method. Psalm parses that natively into `MethodStorage::$removed_taints`; nothing here names a
 * specific guard package, so any library or app-local guard opts in with that one docblock line.
 *
 * TRUST MODEL: this is a policy, not a proof, same as any `@psalm-taint-escape`. Accepted
 * consequences: block-vs-log is not statically distinguishable; the middleware array is read from
 * `middleware()`'s DECLARED return type, never its body (an empty-array body still exempts);
 * receiver and guard are BOUNDS, checked against every analysed subclass ({@see
 * substitutedInDescendant()}, {@see guardEscapesEverywhere()}) but not against subclasses outside
 * the analysed project; a class-string entry can be container-rebound to something else at runtime.
 * Suppression is opt-in by annotation, so no plugin config flag gates it.
 *
 * MECHANISM: applied at issue emission, never by editing the taint graph, so no decision here leaks
 * onto an unrelated flow (`docs/contributing/decisions.md`, "Call-site sink exemptions..."). The
 * handler is stateless — `BeforeAddIssueEvent` fires in the main process after workers exit — so no
 * `reset()` registration is needed.
 *
 * Each gate below misses toward null (finding retained):
 * 1. Issue is `TaintedLlmPrompt` (`TaintKind::INPUT_LLM_PROMPT` maps to exactly this issue class).
 * 2. Journey tail label is `call to <class>::prompt|stream`. Confirmed on 7.0.0-beta19: `<class>`
 *    is the call's RECEIVER, not the declaring trait/base (`Methods::getCasedMethodId()` returns
 *    the fq name whenever it's not all-lowercase) — so an interface/union receiver labels the
 *    interface, which fails gate 4/5 for free.
 * 3. Receiver has classlike storage (unpopulated = not proven, not thrown).
 * 4. The sink method is declared by `Laravel\Ai\Promptable` ({@see declaredBy()}) — otherwise a
 *    userland class could self-declare a `prompt()` sink and borrow an unrelated guard stack.
 * 5. Receiver implements `Laravel\Ai\Contracts\HasMiddleware` — laravel/ai only calls
 *    `middleware()` behind that check, so without it the stack is dead code at runtime.
 * 6. `middleware()` resolves, and every analysed subclass resolves it to the SAME declaration
 *    ({@see substitutedInDescendant()}).
 * 7. `middleware()`'s declared return type names an element as an object or class-string; a bare
 *    `array`/`mixed`, a closure entry, or a template bound yields no candidate.
 * 8. Some candidate, and every analysed subclass of it, escapes `llm_prompt` on whichever method
 *    `Illuminate\Pipeline\Pipeline` would actually invoke ({@see dispatchedMethodEscapes()}, {@see
 *    guardEscapesEverywhere()}).
 *
 * @see https://genai.owasp.org/llmrisk/llm01-prompt-injection/ OWASP LLM01:2025
 *
 * @psalm-api
 */
final class PromptGuardTaintHandler implements BeforeAddIssueInterface
{
    /** `queue()`/`broadcast*()` share the same pipeline but are deliberately left reporting for now. */
    private const SINK_CALL_LABEL_PATTERN = '/^call to (.+)::(prompt|stream)$/i';

    /** Lowercased FQN; gate 4 requires the sink to actually be laravel/ai's pipeline. */
    private const PROMPTABLE_TRAIT = 'laravel\ai\promptable';

    /** Lowercased, matching the key spelling in `ClassLikeStorage::$class_implements`. */
    private const HAS_MIDDLEWARE_INTERFACE = 'laravel\ai\contracts\hasmiddleware';

    private const MIDDLEWARE_METHOD = 'middleware';

    /** `Illuminate\Pipeline\Pipeline::$method` — dispatched for a class-string or non-callable object pipe. */
    private const GUARD_METHOD = 'handle';

    /** The method `Pipeline` reaches instead whenever it invokes the pipe directly. */
    private const INVOKE_METHOD = '__invoke';

    /**
     * Reads storage only, enforcing the "never edit the taint graph" ADR constraint.
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
            || !self::declaredBy($receiver, $sink['method'], self::PROMPTABLE_TRAIT)
            || !isset($receiver->class_implements[self::HAS_MIDDLEWARE_INTERFACE])
        ) {
            return null;
        }

        $middleware = self::methodStorage($codebase, $receiver, self::MIDDLEWARE_METHOD);

        if (!$middleware instanceof MethodStorage
            || self::substitutedInDescendant($codebase, $receiver, self::MIDDLEWARE_METHOD)
            || !$middleware->return_type instanceof Union
        ) {
            return null;
        }

        foreach (self::middlewareCandidates($middleware->return_type) as $candidate) {
            if (self::guardEscapesEverywhere($codebase, $candidate['name'], $candidate['as_object'])) {
                return false;
            }
        }

        return null;
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

        return ['class' => $matches[1], 'method' => \strtolower($matches[2])];
    }

    /**
     * True when `$methodName` resolves to a declaration on `$declaringClassLc` — the label alone
     * doesn't prove provenance, since any class can self-declare a same-named sink (gate 4).
     *
     * @psalm-mutation-free
     */
    private static function declaredBy(ClassLikeStorage $storage, string $methodName, string $declaringClassLc): bool
    {
        $methodId = $storage->declaring_method_ids[$methodName] ?? null;

        return $methodId !== null
            && \str_starts_with(\strtolower((string) $methodId), $declaringClassLc . '::');
    }

    /**
     * An unpopulated or unseen classlike is "not proven", not a throw — the Eloquent handlers' idiom.
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
     * Resolves `$methodName` via `declaring_method_ids`, inherited declarations included; the
     * returned storage's `$return_type` is the declared signature, never an inferred type.
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
     * True when some analysed subclass resolves `$methodName` to a different declaration than
     * `$storage` — the journey label names the receiver's STATIC type, but the real call dispatches
     * on the runtime object, so an unguarded subclass would otherwise inherit the base's exemption.
     *
     * Compares DECLARING method ids ({@see descendantsOf()}) rather than
     * `MethodStorage::$overridden_downstream`: that flag is only set for a method stored directly
     * on the child, so a child that swaps the method via a TRAIT import never sets it but does
     * change its declaring id, which this catches.
     *
     * ACCEPTED GAP: a subclass outside the analysed project is invisible and can still inherit the
     * exemption undetected.
     *
     * @psalm-mutation-free
     */
    private static function substitutedInDescendant(Codebase $codebase, ClassLikeStorage $storage, string $methodName): bool
    {
        $declared = $storage->declaring_method_ids[$methodName] ?? null;

        if ($declared === null) {
            return false;
        }

        $descendants = self::descendantsOf($codebase, $storage);

        if ($descendants === null) {
            return true;
        }

        foreach ($descendants as $descendant) {
            $descendantDeclared = $descendant->declaring_method_ids[$methodName] ?? null;

            if ($descendantDeclared === null || (string) $descendantDeclared !== (string) $declared) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every analysed classlike below `$storage`, or null if one is unreadable (fails toward a
     * retained finding). `ClassLikeStorage::$dependent_classlikes` is NOT transitively closed —
     * `Populator` records only direct links and merges once, no fixpoint, so it reaches ~2 levels
     * (`A < B < C < D`: `A` lists `B`, `C`, never `D`; confirmed on 7.0.0-beta19 with a 4-level
     * hierarchy). Hence the worklist walk here, with a visited set for cycle safety.
     *
     * @return array<string, ClassLikeStorage>|null
     *
     * @psalm-mutation-free
     */
    private static function descendantsOf(Codebase $codebase, ClassLikeStorage $storage): ?array
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

        return $found;
    }

    /**
     * Middleware entries from the VALUE position of `middleware()`'s declared array type, tagged
     * by form since `Pipeline` dispatches them differently ({@see dispatchedMethodEscapes()}):
     * `list<Guard>`/`array<int, Guard>` is an OBJECT entry, `class-string<Guard>`/`Guard::class` is
     * resolved through the container first. A bare `array`/`mixed` value type yields nothing.
     *
     * ACCEPTED LIMITATION: a closure entry contributes nothing — its body is where the guard would
     * live, and no declared type can carry an escape annotation for it. Pinned by
     * `PromptGuardClosureMiddlewareKnownLimitation.phpt`.
     *
     * @return list<array{name: string, as_object: bool}>
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
                // TClosure extends TNamedObject; TTemplateParamClass (a class-string<T> bound)
                // extends TClassString — both must be skipped explicitly or they'd be misread.
                if ($value instanceof TClosure || $value instanceof TTemplateParamClass) {
                    continue;
                }

                if ($value instanceof TNamedObject) {
                    $candidates[] = ['name' => $value->value, 'as_object' => true];

                    continue;
                }

                if ($value instanceof TLiteralClassString) {
                    $candidates[] = ['name' => $value->value, 'as_object' => false];

                    continue;
                }

                // Bare `class-string` leaves `$as_type` null and names no candidate.
                if ($value instanceof TClassString
                    && $value->as_type instanceof TNamedObject
                    && !$value->as_type instanceof TClosure
                ) {
                    $candidates[] = ['name' => $value->as_type->value, 'as_object' => false];
                }
            }
        }

        return $candidates;
    }

    /**
     * True when `$guardName` and every analysed subclass escapes `llm_prompt` on the dispatched
     * method. `@return list<Guard>` is a BOUND, satisfied by any subclass — including one that
     * overrides without the escape, or one that adds `__invoke` and shifts the dispatch path — so
     * the whole analysed hierarchy must hold the claim. Same open-world gap as
     * {@see substitutedInDescendant()}.
     *
     * @psalm-mutation-free
     */
    private static function guardEscapesEverywhere(Codebase $codebase, string $guardName, bool $asObject): bool
    {
        $guard = self::classStorage($codebase, $guardName);

        if (!$guard instanceof ClassLikeStorage || !self::dispatchedMethodEscapes($codebase, $guard, $asObject)) {
            return false;
        }

        $descendants = self::descendantsOf($codebase, $guard);

        if ($descendants === null) {
            return false;
        }

        foreach ($descendants as $descendant) {
            if (!self::dispatchedMethodEscapes($codebase, $descendant, $asObject)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the method `Pipeline::carry()` would actually invoke carries the escape. Dispatch
     * order mirrors `carry()` (`vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php`),
     * which differs by entry form: an OBJECT entry hits `is_callable()` first, so `__invoke` wins
     * over an adjacent `handle()`; a class-STRING entry isn't callable and takes the container
     * branch, where `handle()` wins. Only the first method that EXISTS is consulted — no fallback
     * to the other on a missing annotation, since runtime has none either.
     *
     * @psalm-mutation-free
     */
    private static function dispatchedMethodEscapes(Codebase $codebase, ClassLikeStorage $guard, bool $asObject): bool
    {
        $dispatchOrder = $asObject
            ? [self::INVOKE_METHOD, self::GUARD_METHOD]
            : [self::GUARD_METHOD, self::INVOKE_METHOD];

        foreach ($dispatchOrder as $methodName) {
            $dispatched = self::methodStorage($codebase, $guard, $methodName);

            if (!$dispatched instanceof MethodStorage) {
                continue;
            }

            return ($dispatched->removed_taints & TaintKind::INPUT_LLM_PROMPT) !== 0;
        }

        return false;
    }
}
