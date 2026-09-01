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
 * Suppresses `TaintedLlmPrompt` on a `prompt()` / `stream()` call whose receiver class declares an
 * agent middleware stack containing a guard that claims to neutralize prompt injection.
 *
 * The claim is the guard author's, written as `@psalm-taint-escape llm_prompt` on the middleware's
 * `handle()` method. Psalm parses that natively into `FunctionLikeStorage::$removed_taints`, so no
 * custom annotation, no vendor FQN, and no AST read is involved: any guard library, and any
 * app-local guard, opts in by adding that one docblock line. Nothing in this file names a specific
 * guard package.
 *
 * TRUST MODEL — this is a policy, not a proof. The annotation says a mitigation is attached, not
 * that any given payload is neutralized, exactly like every other `@psalm-taint-escape`. Two
 * consequences are accepted deliberately:
 *
 * - Whether the guard blocks or merely logs is not statically distinguishable (the action is
 *   typically constructor state resolved from runtime config), so the annotation is taken at face
 *   value.
 * - The middleware ARRAY is read from the declared return type of `middleware()`, never from its
 *   body. A body that returns the declared guard only on some branch still counts.
 * - `list<Guard>` says the entries are Guards, not that there IS one: a body returning `[]`
 *   satisfies the declared type and still exempts. The declaration is the author's claim, exactly
 *   as with the escape itself.
 * - Both the receiver and the declared element type are BOUNDS, not exact runtime classes. Every
 *   analysed subclass of each is checked ({@see substitutedInDescendant()},
 *   {@see guardEscapesEverywhere()}), but a subclass OUTSIDE the analysed project is invisible, so
 *   a library's exemption can be inherited by an override the analysis never saw.
 * - A class-string entry is resolved through the container at runtime, so a binding can swap the
 *   named guard for something else entirely. Not provable statically, same trust layer as the
 *   guard's own configuration.
 *
 * Suppression is therefore opt-in by annotation; a project that writes no such docblock is
 * unaffected, so no plugin config flag gates it.
 *
 * MECHANISM. The verdict is applied when the issue is emitted, and the taint graph is never
 * edited, so no decision made here can leak onto an unrelated flow — see
 * `docs/contributing/decisions.md`, "Call-site sink exemptions are applied at issue emission".
 * The handler is stateless: `BeforeAddIssueEvent` is dispatched from the main process after the
 * analysis workers exit, so nothing recorded during analysis would survive anyway, and every fact
 * below is re-derived from the issue plus classlike storage. No `reset()` registration is needed.
 *
 * WHAT IS ENFORCED (each miss returns null, which retains the finding):
 *
 * 1. The issue is a `TaintedLlmPrompt`. `TaintKind::INPUT_LLM_PROMPT` maps to exactly one issue
 *    class, so suppressing it is exactly "strip only llm_prompt": a `TaintedSql` or `TaintedHtml`
 *    on the same value is a different issue object and never reaches gate 2.
 * 2. The journey tail label is `call to <class>::prompt|stream`. Empirically confirmed on Psalm
 *    7.0.0-beta19: the tail is the ARGUMENT node feeding the sink, and `<class>` is the RECEIVER
 *    class, not the declaring trait or base (`Internal/Codebase/Methods::getCasedMethodId()`
 *    returns the original fq class name whenever it is not all-lowercase). An interface- or
 *    union-typed receiver therefore labels the interface, whose storage fails gate 4 or 5 for
 *    free. A receiver class whose FQN is entirely lowercase would label the DECLARING class
 *    instead; pathological, and it fails toward a retained finding on the union case.
 * 3. That class has classlike storage. A lookup made before the classlike is populated proves
 *    nothing, so it is treated as "not proven" rather than thrown.
 * 4. The sink method resolves to a declaration on `Laravel\Ai\Promptable`, so the call really is
 *    into laravel/ai's pipeline ({@see declaredBy()}). A userland class is free to name a method
 *    `prompt()`, annotate it `@psalm-taint-sink llm_prompt`, and implement `HasMiddleware`; its
 *    middleware never runs, and exempting it would be suppressing someone else's sink.
 * 5. The class implements `Laravel\Ai\Contracts\HasMiddleware`. laravel/ai only ever calls
 *    `middleware()` behind an `instanceof HasMiddleware` check
 *    (`Providers/Concerns/GeneratesText::gatherMiddlewareFor()`), so a class that declares
 *    `middleware()` without the interface has a stack that is dead code at runtime. Matched
 *    against `class_implements`, which is transitive, so an inherited implementation passes.
 * 6. A `middleware()` method is resolvable, and every analysed subclass resolves it to the SAME
 *    declaration ({@see substitutedInDescendant()}). Inherited without a substitution is accepted:
 *    the runtime inherits it too.
 * 7. `middleware()` carries a declared return type naming its element type, as an object
 *    (`list<Guard>`) or as a class-string (`list<class-string<Guard>>`, `list<Guard::class>`).
 *    A bare native `array` (or `mixed`) yields no candidates and declines, and so do a closure
 *    entry and a template bound, neither of which names the class that actually runs.
 * 8. Some candidate, AND every analysed subclass of it, removes `llm_prompt` on the method
 *    `Illuminate\Pipeline\Pipeline` would invoke on it: `__invoke` for an object entry that has
 *    one, `handle` otherwise ({@see dispatchedMethodEscapes()} for the mirror,
 *    {@see guardEscapesEverywhere()} for the hierarchy walk). An escape parked on a method the
 *    pipeline never reaches is not the guard's entry point and does not qualify.
 *
 * @see https://genai.owasp.org/llmrisk/llm01-prompt-injection/ OWASP LLM01:2025
 *
 * @psalm-api
 */
final class PromptGuardTaintHandler implements BeforeAddIssueInterface
{
    /**
     * The sink methods this exemption covers, as they appear in the journey tail label. Only the
     * two synchronous entry points are listed. `queue()` / `broadcast*()` route through the same
     * middleware pipeline at runtime and are deliberately left reporting for now.
     */
    private const SINK_CALL_LABEL_PATTERN = '/^call to (.+)::(prompt|stream)$/i';

    /**
     * Lowercased FQN of the trait that declares the covered sinks. The exemption is only about
     * laravel/ai's middleware pipeline, so the call has to actually be into that pipeline.
     */
    private const PROMPTABLE_TRAIT = 'laravel\ai\promptable';

    /** Lowercased, matching the key spelling in `ClassLikeStorage::$class_implements`. */
    private const HAS_MIDDLEWARE_INTERFACE = 'laravel\ai\contracts\hasmiddleware';

    private const MIDDLEWARE_METHOD = 'middleware';

    /**
     * `Illuminate\Pipeline\Pipeline::$method`, the name it calls on a pipe it resolved from a
     * class-string, or on an object that is not itself callable.
     */
    private const GUARD_METHOD = 'handle';

    /** The method `Pipeline` reaches instead whenever it invokes the pipe directly. */
    private const INVOKE_METHOD = '__invoke';

    /**
     * Reads storage and returns a verdict; it writes nothing, here or into the taint graph. The
     * annotation is what self-analysis demands once every helper below is mutation-free, and it
     * also states the ADR's constraint in a form the analyzer enforces.
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
     * The receiver class and sink method named by the journey's tail label, when that tail is the
     * argument node of a covered prompt sink call.
     *
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
     * True when `$methodName` on `$storage` resolves to a declaration on `$declaringClassLc`.
     *
     * The label alone proves nothing about provenance: any class may name a method `prompt()` and
     * annotate it `@psalm-taint-sink llm_prompt`, and such a class can implement `HasMiddleware`
     * and declare a perfectly good guard stack while never running laravel/ai's pipeline at all.
     * Exempting it would be suppressing someone else's sink on evidence about ours.
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
     * A classlike Psalm has not populated yet answers no question here, and neither does one it
     * never saw, so both are "not proven" rather than a throw. Mirrors the storage-lookup idiom
     * used by the Eloquent handlers.
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
     * The storage of `$methodName` as resolved for `$storage`, inherited declarations included:
     * `declaring_method_ids` reaches whichever class actually declares the method, and its
     * `$return_type` is what that signature and docblock said, never an inferred type.
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
     * True when some analysed subclass of `$storage` resolves `$methodName` to a different
     * declaration than `$storage` does, so the verdict just proven does not describe every object
     * the call site could receive.
     *
     * The journey label names the receiver's STATIC type, but `gatherMiddlewareFor()` calls
     * `middleware()` on the actual object. The shape needs no adversarial author: a guarded base
     * exposes `$this->prompt()`, a subclass returns an empty stack, and the label still says the
     * base.
     *
     * Descendants come from {@see descendantsOf()}. Comparing DECLARING method ids rather than
     * reading `MethodStorage::$overridden_downstream` is deliberate: that flag is only set for a
     * method stored directly on the child (`Populator::populateClassLikeStorage()`), so a child
     * that replaces the stack by importing a TRAIT never sets it, while its declaring id changes
     * to the trait's and is caught here. A descendant that does not override at all keeps the
     * ancestor's declaring id and costs nothing. A `final` class has no descendants, so it needs
     * no special case.
     *
     * ACCEPTED GAP: only analysed code is visible. A subclass shipped by a downstream consumer of
     * an analysed library is not in the set, so a library's exemption can still be inherited by an
     * override the analysis never saw.
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
     * Every analysed classlike below `$storage`, keyed by lowercased name, or null when one of
     * them cannot be read (which proves nothing, so callers must fail toward a retained finding).
     *
     * `ClassLikeStorage::$dependent_classlikes` is NOT the closure it looks like.
     * `Populator::populateClassLikeStorage()` records DIRECT links, and `populateCodebase()` then
     * makes a SINGLE merge pass over them with no fixpoint, so the stored set reaches about two
     * levels: in a chain `A < B < C < D`, `A` lists `B` and `C` and never `D`. Executed on
     * 7.0.0-beta19 with a four-level agent hierarchy; the level-four override was invisible and
     * silently kept its ancestor's exemption. Hence the fixpoint walk here, with a visited set so
     * a diamond or a `+=`-induced cycle terminates.
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
     * The middleware entries named by the VALUE position of an array-shaped type, each tagged with
     * the form it is written in, because `Illuminate\Pipeline\Pipeline` dispatches the two forms
     * differently (see {@see dispatchedMethodEscapes()}):
     *
     * - `list<Guard>` / `array<int, Guard>` — an OBJECT entry (`as_object: true`).
     * - `list<class-string<Guard>>` and `list<Guard::class>` — a class-STRING entry, resolved out
     *   of the container before dispatch (`as_object: false`).
     *
     * `list<Guard>` and `array{Guard}` arrive as `TKeyedArray`, `array<int, Guard>` as `TArray`; a
     * bare `array` degenerates to a `mixed` value type and yields nothing, which is the intended
     * decline.
     *
     * ACCEPTED LIMITATION: a closure entry contributes NOTHING and can never be exempted. A
     * closure's body is where the guard would live, and no declared type can carry an escape
     * annotation for it, so the claim is unprovable by construction; an escape docblock written
     * above the closure literal is ignored. Pinned by
     * `PromptGuardClosureMiddlewareKnownLimitation.phpt`, documented in
     * `docs/contributing/taint-analysis.md`. The explicit `TClosure` skip below states that
     * intent: `TClosure` extends `TNamedObject`, so without it a closure would be read as a class
     * named `Closure` — which declines anyway, since `Closure::__invoke()` carries no escape.
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
                // `class-string<T of Guard>` is a BOUND standing in for a class the caller picks,
                // so the bound's annotation says nothing about what actually runs.
                // `TTemplateParamClass` extends `TClassString`, so it would otherwise be read as
                // the bound itself.
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

                // `class-string<Guard>` keeps the bound in `$as_type`; a bare `class-string` leaves
                // it null and names no candidate.
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
     * True when `$guardName` AND every analysed subclass of it removes `llm_prompt` on the method
     * `Illuminate\Pipeline\Pipeline` would dispatch to it.
     *
     * The declared element type is a BOUND, not the exact runtime class: `@return list<Guard>` is
     * satisfied by any subclass, including one that overrides the annotated method without the
     * escape, or one that merely ADDS `__invoke` and so moves an object entry onto a different
     * dispatch path. Requiring the whole analysed hierarchy to hold the claim covers both without
     * forcing guard classes to be `final`. A subclass that overrides nothing inherits the
     * annotated method and passes for free.
     *
     * Same open-world gap as {@see substitutedInDescendant()}: a subclass outside the analysed
     * project is invisible.
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
     * True when the method `Illuminate\Pipeline\Pipeline` would actually call on this middleware
     * carries `@psalm-taint-escape llm_prompt`. Psalm folds that docblock into
     * `FunctionLikeStorage::$removed_taints` during the scan phase, so reading the bitmask back
     * here needs no annotation machinery of our own.
     *
     * The method is chosen by mirroring `Pipeline::carry()`
     * (`vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php`), whose order differs by
     * entry form and was confirmed against PHP's own `is_callable()`:
     *
     * - An OBJECT entry hits `is_callable($pipe)` first, which is true exactly when the class has
     *   `__invoke`, so `__invoke` WINS over a `handle()` sitting next to it. Without `__invoke`,
     *   the object branch falls through to `method_exists($pipe, 'handle')`.
     * - A class-STRING entry is not callable, so it takes the container branch, and the
     *   `method_exists($pipe, 'handle')` test then makes `handle()` win over `__invoke`.
     *
     * Only the FIRST method that exists is consulted; there is no fallthrough to the other one on
     * a missing annotation, because runtime has no such fallthrough either. So an annotated
     * `handle()` on an object entry whose class also declares an unannotated `__invoke()` does not
     * qualify: that `handle()` is never reached.
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
