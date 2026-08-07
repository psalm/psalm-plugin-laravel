<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Config;

/**
 * Default reporting level for `TaintedLlmPrompt`, the D-in half of the
 * `laravel/ai` integration (untrusted input reaching a prompt).
 *
 * Advisory by default, unlike every other taint kind the plugin ships. The
 * difference is remediation, not confidence: an SQL or XSS finding names its
 * own fix (parameterize, escape), whereas no reliable prompt-injection
 * sanitizer exists, so there is nothing to hand a developer whose chatbot
 * legitimately passes `$request->input('message')` to an agent. Reported as an
 * error, the rule fires on the product's primary use case with `@psalm-suppress`
 * as the only way out, and a rule whose remediation is "suppress it" gets
 * suppressed everywhere, including where it mattered.
 *
 * Advisory therefore means visible under `--show-info=true` and never
 * build-breaking, until a project asks for enforcement with
 * `<findPromptInjection value="true" />`.
 *
 * This governs the prompt SINK direction only. Model output as a taint SOURCE
 * (`$response->text` reaching SQL, HTML, shell, header or file sinks) reports
 * as the usual `Tainted*` issues at their usual levels, because those DO have
 * an ordinary fix.
 *
 * @internal
 * @psalm-external-mutation-free
 */
final class PromptInjectionIssuePolicy
{
    /**
     * Psalm core issue, emitted from `TaintKind::INPUT_LLM_PROMPT`; the plugin
     * contributes the `laravel/ai` sinks that reach it, not the issue class.
     */
    private const ISSUE_TYPE = 'TaintedLlmPrompt';

    /** @psalm-external-mutation-free */
    public static function apply(bool $enforced): void
    {
        DefaultIssueLevels::apply(
            [self::ISSUE_TYPE],
            $enforced ? Config::REPORT_ERROR : Config::REPORT_INFO,
        );
    }
}
