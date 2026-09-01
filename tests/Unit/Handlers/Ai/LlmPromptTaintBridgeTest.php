<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Ai;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Ai\LlmPromptTaintBridge;
use Psalm\LaravelPlugin\Handlers\Validation\ValidationRuleAnalyzer;
use Psalm\Storage\MethodStorage;

/**
 * Psalm 6 has no `TaintKind::INPUT_LLM_PROMPT` (see {@see LlmPromptTaintBridge}'s docblock), so
 * `llm_prompt` can only ever match a sink by being present verbatim on a source's taint list. These
 * tests pin the superset rule that decides which sources qualify — driven through
 * {@see LlmPromptTaintBridge::widen()} with synthetic storage the way
 * {@see \Psalm\LaravelPlugin\Handlers\Auth\GuardTaintHandler::annotateGuardTaints()} is.
 */
#[CoversClass(LlmPromptTaintBridge::class)]
final class LlmPromptTaintBridgeTest extends TestCase
{
    #[Test]
    public function a_full_all_input_source_gains_the_llm_prompt_kind(): void
    {
        $storage = new MethodStorage();
        $storage->taint_source_types = ValidationRuleAnalyzer::allInputTaints();

        LlmPromptTaintBridge::widen($storage);

        $this->assertContains('llm_prompt', $storage->taint_source_types);
    }

    #[Test]
    public function a_partial_source_is_not_widened(): void
    {
        // Superset, not any-intersection: a method sourced with only `html` must not gain the
        // llm_prompt bit either, or this would over-report relative to what Psalm 7 actually flags.
        $storage = new MethodStorage();
        $storage->taint_source_types = ['html'];

        LlmPromptTaintBridge::widen($storage);

        $this->assertSame(['html'], $storage->taint_source_types);
    }

    #[Test]
    public function widening_is_idempotent(): void
    {
        $storage = new MethodStorage();
        $storage->taint_source_types = ValidationRuleAnalyzer::allInputTaints();

        LlmPromptTaintBridge::widen($storage);
        LlmPromptTaintBridge::widen($storage);

        $this->assertSame(1, \array_count_values($storage->taint_source_types)['llm_prompt']);
    }

    #[Test]
    public function a_full_all_input_escape_gains_the_llm_prompt_kind(): void
    {
        // Faithful to Psalm 7/master: a single shared ALL_INPUT bitmask means an ordinary
        // @psalm-taint-escape input strips the llm_prompt bit along with the rest there too.
        $storage = new MethodStorage();
        $storage->removed_taints = ValidationRuleAnalyzer::allInputTaints();

        LlmPromptTaintBridge::widen($storage);

        $this->assertContains('llm_prompt', $storage->removed_taints);
    }

    #[Test]
    public function a_narrow_escape_is_not_widened(): void
    {
        // e.g. GuardTaintHandler's SessionGuard::hashPasswordForCookie() removes only user_secret —
        // must not gain llm_prompt, since it never escaped ordinary input taint to begin with.
        $storage = new MethodStorage();
        $storage->removed_taints = ['user_secret'];

        LlmPromptTaintBridge::widen($storage);

        $this->assertSame(['user_secret'], $storage->removed_taints);
    }

    #[Test]
    public function an_unrelated_source_is_untouched(): void
    {
        $storage = new MethodStorage();

        LlmPromptTaintBridge::widen($storage);

        $this->assertSame([], $storage->taint_source_types);
        $this->assertSame([], $storage->removed_taints);
    }
}
