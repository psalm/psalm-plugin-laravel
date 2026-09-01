<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Ai;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Issue\TaintedLlmPrompt;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
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
 *    union-typed receiver therefore labels the interface, whose storage fails gate 3 or 4 for
 *    free. A receiver class whose FQN is entirely lowercase would label the DECLARING class
 *    instead; pathological, and it fails toward a retained finding on the union case.
 * 3. That class has classlike storage. A lookup made before the classlike is populated proves
 *    nothing, so it is treated as "not proven" rather than thrown.
 * 4. The class implements `Laravel\Ai\Contracts\HasMiddleware`. laravel/ai only ever calls
 *    `middleware()` behind an `instanceof HasMiddleware` check
 *    (`Providers/Concerns/GeneratesText::gatherMiddlewareFor()`), so a class that declares
 *    `middleware()` without the interface has a stack that is dead code at runtime. Matched
 *    against `class_implements`, which is transitive, so an inherited implementation passes.
 * 5. A `middleware()` method is resolvable. Inherited is accepted: the runtime inherits it too.
 * 6. `middleware()` carries a declared return type naming its element type. A bare native `array`
 *    (or `mixed`) yields no candidates and declines.
 * 7. Some element type's own `handle()` removes `llm_prompt`. The method name is fixed at
 *    `handle` because that is the only method laravel/ai's middleware pipeline invokes; an escape
 *    annotation parked on any other method is not the guard's entry point.
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
    private const SINK_CALL_LABEL_PATTERN = '/^call to (.+)::(?:prompt|stream)$/i';

    /** Lowercased, matching the key spelling in `ClassLikeStorage::$class_implements`. */
    private const HAS_MIDDLEWARE_INTERFACE = 'laravel\ai\contracts\hasmiddleware';

    private const MIDDLEWARE_METHOD = 'middleware';

    /** The single method laravel/ai's prompt middleware pipeline invokes on each entry. */
    private const GUARD_METHOD = 'handle';

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

        $receiverName = self::sinkReceiverClass($issue->journey);

        if ($receiverName === null) {
            return null;
        }

        $codebase = $event->getCodebase();
        $receiver = self::classStorage($codebase, $receiverName);

        if (!$receiver instanceof ClassLikeStorage || !isset($receiver->class_implements[self::HAS_MIDDLEWARE_INTERFACE])) {
            return null;
        }

        $middleware = self::declaredReturnType($codebase, $receiver, self::MIDDLEWARE_METHOD);

        if (!$middleware instanceof Union) {
            return null;
        }

        foreach (self::elementClasses($middleware) as $guardName) {
            if (self::escapesPromptTaint($codebase, $guardName)) {
                return false;
            }
        }

        return null;
    }

    /**
     * The receiver class named by the journey's tail label, when that tail is the argument node of
     * a covered prompt sink call.
     *
     * @param list<array{location: ?\Psalm\CodeLocation, label: string, entry_path_type: string}> $journey
     *
     * @psalm-pure
     */
    private static function sinkReceiverClass(array $journey): ?string
    {
        $tail = $journey === [] ? null : $journey[\count($journey) - 1];

        if ($tail === null || \preg_match(self::SINK_CALL_LABEL_PATTERN, $tail['label'], $matches) !== 1) {
            return null;
        }

        return $matches[1];
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
     * The DECLARED return type of `$methodName` as resolved for `$storage`, inherited declarations
     * included. Never an inferred one: `declaring_method_ids` reaches the storage of whichever
     * class actually declares the method, and `$return_type` there is what its signature and
     * docblock said.
     *
     * @psalm-mutation-free
     */
    private static function declaredReturnType(Codebase $codebase, ClassLikeStorage $storage, string $methodName): ?Union
    {
        $methodId = $storage->declaring_method_ids[$methodName] ?? null;

        if ($methodId === null) {
            return null;
        }

        try {
            return $codebase->methods->getStorage($methodId)->return_type;
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Class names appearing in the VALUE position of an array-shaped type. `list<Guard>` and
     * `array{Guard}` arrive as `TKeyedArray`, `array<int, Guard>` as `TArray`; a bare `array`
     * degenerates to a `mixed` value type and yields nothing, which is the intended decline.
     *
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    private static function elementClasses(Union $type): array
    {
        $names = [];

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
                if ($value instanceof TNamedObject) {
                    $names[] = $value->value;
                }
            }
        }

        return $names;
    }

    /**
     * True when `$guardName::handle()` carries `@psalm-taint-escape llm_prompt`. Psalm folds that
     * docblock into `FunctionLikeStorage::$removed_taints` during the scan phase, so reading the
     * bitmask back here needs no annotation machinery of our own.
     *
     * @psalm-mutation-free
     */
    private static function escapesPromptTaint(Codebase $codebase, string $guardName): bool
    {
        $guard = self::classStorage($codebase, $guardName);

        if (!$guard instanceof ClassLikeStorage) {
            return false;
        }

        $methodId = $guard->declaring_method_ids[self::GUARD_METHOD] ?? null;

        if ($methodId === null) {
            return false;
        }

        try {
            $handle = $codebase->methods->getStorage($methodId);
        } catch (\UnexpectedValueException) {
            return false;
        }

        return ($handle->removed_taints & TaintKind::INPUT_LLM_PROMPT) !== 0;
    }
}
