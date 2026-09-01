<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Ai;

use Psalm\Internal\Provider\FileStorageProvider;
use Psalm\LaravelPlugin\Handlers\Validation\ValidationRuleAnalyzer;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\FunctionLikeStorage;

/**
 * Psalm 6 has no `TaintKind::INPUT_LLM_PROMPT` / `TaintedLlmPrompt` (see
 * {@see \Psalm\LaravelPlugin\Internal\PromptInjectionIssuePolicy}'s docblock): the docblock keyword
 * `@psalm-taint-source input` expands to a fixed `TaintKindGroup::ALL_INPUT` array that predates the
 * laravel/ai integration, so it never carries `llm_prompt` — meaning the `@psalm-taint-sink
 * llm_prompt` annotations across `stubs/integrations/laravel-ai/` never match anything on this Psalm
 * major. Confirmed empirically (installed `laravel/ai` and ran the `PromptInjection` phpt suite):
 * without this class, D-in prompt-injection detection is a total silent no-op, not even the generic
 * `TaintedCustom` fallback.
 *
 * Psalm 7 closes this by making `TaintKind::ALL_INPUT` an int bitmask with every bit set, so any
 * ordinary "all user input" source automatically also carries the `llm_prompt` bit there. This class
 * reproduces that same semantics on Psalm 6 as a plain string: any method/function storage whose
 * `taint_source_types` is already a full `ALL_INPUT` superset also gets `llm_prompt` appended, so it
 * intersects with the sink stubs and surfaces as the generic `TaintedCustom` issue (Psalm 6 has no
 * dedicated issue class for a custom kind — see `docs/config.md#findpromptinjection`). The same
 * superset widening applies to `removed_taints`, so a value already fully validated/escaped for
 * ordinary input taint does not still read as an LLM-prompt-injection risk — faithful to Psalm 7,
 * where `@psalm-taint-escape input` strips every `ALL_INPUT` bit, including the `llm_prompt` one, in
 * the same operation.
 *
 * Superset, not any-intersection: a method sourced with only e.g. `html` must not gain the
 * `llm_prompt` bit either, or this would over-report relative to what Psalm 7 actually flags.
 *
 * Direct superglobal access (`$_GET`, `$_POST`, ...) is untouched: Psalm hardcodes
 * `TaintKindGroup::ALL_INPUT` for those in core (`VariableFetchAnalyzer`), bypassing storage
 * entirely, so they stay an accepted false negative on this branch — Laravel code reaches input
 * through `Request`, not superglobals directly.
 *
 * Registered only when the laravel/ai integration is enabled AND `findPromptInjection` has not been
 * explicitly disabled ({@see \Psalm\LaravelPlugin\Plugin::registerHandlers()}): skipping
 * registration entirely, rather than suppressing the resulting `TaintedCustom` issue, avoids
 * coupling the opt-out to this plugin's other `TaintedCustom` sink (`html_url`, see
 * `docs/security.md`).
 *
 * @internal
 */
final class LlmPromptTaintBridge implements AfterCodebasePopulatedInterface
{
    private const LLM_PROMPT = 'llm_prompt';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            foreach ($storage->methods as $method) {
                self::widen($method);
            }
        }

        foreach (FileStorageProvider::getAll() as $file) {
            foreach ($file->functions as $function) {
                self::widen($function);
            }
        }
    }

    /**
     * Split out from {@see afterCodebasePopulated} so it can be driven with synthetic
     * {@see MethodStorage}/{@see FunctionStorage} objects in a unit test, the way
     * {@see \Psalm\LaravelPlugin\Handlers\Auth\GuardTaintHandler::annotateGuardTaints()} is.
     *
     * @internal
     */
    public static function widen(FunctionLikeStorage $storage): void
    {
        $allInput = ValidationRuleAnalyzer::allInputTaints();

        if (self::isSuperset($storage->taint_source_types, $allInput) && !\in_array(self::LLM_PROMPT, $storage->taint_source_types, true)) {
            $storage->taint_source_types[] = self::LLM_PROMPT;
        }

        if (self::isSuperset($storage->removed_taints, $allInput) && !\in_array(self::LLM_PROMPT, $storage->removed_taints, true)) {
            $storage->removed_taints[] = self::LLM_PROMPT;
        }
    }

    /**
     * @param array<string> $haystack
     * @param list<string> $needles
     *
     * @psalm-pure
     */
    private static function isSuperset(array $haystack, array $needles): bool
    {
        return \array_diff($needles, $haystack) === [];
    }
}
