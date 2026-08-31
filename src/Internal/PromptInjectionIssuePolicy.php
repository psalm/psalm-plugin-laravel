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
 * Psalm 6 has no `TaintedLlmPrompt` issue type or `llm_prompt` taint kind: both
 * are Psalm 7 core additions. Psalm 6's taint engine folds any sink kind it does
 * not recognize into the generic `TaintedCustom` issue (shared with this plugin's
 * `html_url` sink), so the D-in finding still fires, but this class's effect on
 * Psalm 6 is a no-op — it configures a level for an issue type Psalm 6 never
 * emits, and Psalm 6's config XSD rejects an explicit `<TaintedLlmPrompt />`
 * issueHandler outright. See `docs/config.md#findpromptinjection`.
 *
 * @internal
 */
final class PromptInjectionIssuePolicy
{
    /**
     * Psalm 7 core issue, emitted from `TaintKind::INPUT_LLM_PROMPT`; the plugin
     * contributes the `laravel/ai` sinks that reach it, not the issue class.
     * Kept as the target type even though Psalm 6 never emits it under this
     * name (see class docblock) — this mirrors Psalm 7 behavior faithfully for
     * when it does apply, rather than silently redirecting to `TaintedCustom`.
     */
    private const ISSUE_TYPE = 'TaintedLlmPrompt';

    // Not marked mutation-free: it calls DefaultIssueLevels::apply(), which is not
    // mutation-free on Psalm 6 (see that class's docblock).
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
