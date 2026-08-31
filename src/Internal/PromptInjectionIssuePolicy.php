<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Config;

/**
 * Opt-out policy for `TaintedLlmPrompt`, the D-in half of the `laravel/ai`
 * integration (untrusted input reaching a prompt). Enabled integration runs
 * use Psalm's normal error default unless configuration explicitly opts out.
 *
 * Psalm's normal default is an error. An explicit false is a narrow opt-out
 * for this D-in issue only: it suppresses `TaintedLlmPrompt`, while ordinary
 * D-out taint sources and their downstream sink issues remain errors.
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
    public static function apply(?bool $configured): void
    {
        // Plugin::__invoke() calls this only after the supported laravel/ai gate
        // succeeds. Null (omitted config) and true resolve to the normal error
        // level; false is the narrow, explicit D-in opt-out. DefaultIssueLevels
        // also recognizes a previous policy-owned handler, allowing sequential
        // plugin invocations to change the mode safely.
        $level = $configured === false ? Config::REPORT_SUPPRESS : Config::REPORT_ERROR;

        DefaultIssueLevels::apply(
            [self::ISSUE_TYPE],
            $level,
        );
    }
}
