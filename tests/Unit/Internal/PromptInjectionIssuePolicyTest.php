<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Config;
use Psalm\LaravelPlugin\Internal\DefaultIssueLevels;
use Psalm\LaravelPlugin\Internal\PromptInjectionIssuePolicy;

#[CoversClass(PromptInjectionIssuePolicy::class)]
#[CoversClass(DefaultIssueLevels::class)]
final class PromptInjectionIssuePolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        (new \ReflectionClass(Config::class))->getProperty('instance')->setValue(null, null);
    }

    /** @return iterable<string, array{?bool, 'error'|'suppress'}> */
    public static function enforcementModes(): iterable
    {
        yield 'auto keeps normal error' => [null, Config::REPORT_ERROR];
        yield 'enforced when enabled' => [true, Config::REPORT_ERROR];
        yield 'explicit opt-out suppresses prompt injection' => [false, Config::REPORT_SUPPRESS];
    }

    #[Test]
    #[DataProvider('enforcementModes')]
    public function it_sets_the_default_level_for_tainted_llm_prompt(?bool $enforced, string $expectedLevel): void
    {
        $config = $this->loadConfig();

        PromptInjectionIssuePolicy::apply($enforced);

        $this->assertSame($expectedLevel, $config->getReportingLevelForFile('TaintedLlmPrompt', __FILE__));
    }

    /**
     * The D-out direction (model output reaching an ordinary sink) has a normal
     * fix, so the policy must not touch it in either mode.
     */
    #[Test]
    #[DataProvider('enforcementModes')]
    public function it_leaves_the_other_taint_issues_alone(?bool $enforced, string $_expectedLevel): void
    {
        $config = $this->loadConfig();

        PromptInjectionIssuePolicy::apply($enforced);

        foreach (['TaintedSql', 'TaintedHtml', 'TaintedShell'] as $issueType) {
            $this->assertSame(Config::REPORT_ERROR, $config->getReportingLevelForFile($issueType, __FILE__));
        }
    }

    /**
     * `TaintedLlmPrompt` is a Psalm 7 core issue type with no Psalm 6 XSD entry
     * (see PromptInjectionIssuePolicy's docblock): an explicit
     * `<TaintedLlmPrompt />` issueHandler is invalid config on Psalm 6, so this
     * scenario cannot occur on that engine.
     */
    #[Test]
    #[DataProvider('enforcementModes')]
    public function an_explicit_issue_handler_always_wins(?bool $enforced, string $_expectedLevel): void
    {
        $this->skipIfExplicitTaintedLlmPromptHandlerUnsupported();

        foreach ([Config::REPORT_ERROR, Config::REPORT_INFO, Config::REPORT_SUPPRESS] as $level) {
            $config = $this->loadConfig(
                '<issueHandlers><TaintedLlmPrompt errorLevel="' . $level . '" /></issueHandlers>',
            );

            PromptInjectionIssuePolicy::apply($enforced);

            $this->assertSame($level, $config->getReportingLevelForFile('TaintedLlmPrompt', __FILE__));
        }
    }

    /** @return iterable<string, array{?bool, ?bool, 'error'|'suppress'}> */
    public static function enforcementFlips(): iterable
    {
        yield 'opt-out to auto' => [false, null, Config::REPORT_ERROR];
        yield 'auto to opt-out' => [null, false, Config::REPORT_SUPPRESS];
        yield 'opt-out to enforced' => [false, true, Config::REPORT_ERROR];
        yield 'enforced to opt-out' => [true, false, Config::REPORT_SUPPRESS];
    }

    #[Test]
    #[DataProvider('enforcementFlips')]
    public function the_default_follows_sequential_invocations_on_the_same_config(
        ?bool $initial,
        ?bool $subsequent,
        string $expectedLevel,
    ): void {
        $config = $this->loadConfig();

        PromptInjectionIssuePolicy::apply($initial);
        PromptInjectionIssuePolicy::apply($subsequent);

        $this->assertSame($expectedLevel, $config->getReportingLevelForFile('TaintedLlmPrompt', __FILE__));
    }

    /**
     * `TaintedLlmPrompt` is a Psalm 7 core issue type with no Psalm 6 XSD entry
     * (see PromptInjectionIssuePolicy's docblock): an explicit
     * `<TaintedLlmPrompt />` issueHandler is invalid config on Psalm 6, so this
     * scenario cannot occur on that engine.
     */
    #[Test]
    public function an_explicit_handler_stays_unchanged_across_sequential_invocations(): void
    {
        $this->skipIfExplicitTaintedLlmPromptHandlerUnsupported();

        $config = $this->loadConfig(
            '<issueHandlers><TaintedLlmPrompt errorLevel="suppress" /></issueHandlers>',
        );

        PromptInjectionIssuePolicy::apply(false);
        PromptInjectionIssuePolicy::apply(true);

        $this->assertSame(Config::REPORT_SUPPRESS, $config->getReportingLevelForFile('TaintedLlmPrompt', __FILE__));
    }

    private function loadConfig(string $body = ''): Config
    {
        return Config::loadFromXML(
            \dirname(__DIR__, 3),
            '<?xml version="1.0"?><psalm errorLevel="1" xmlns="https://getpsalm.org/schema/config">' . $body . '</psalm>',
        );
    }

    /**
     * Capability probe (never a version check, per project convention): confirms
     * whether the installed Psalm engine's config XSD recognizes `TaintedLlmPrompt`
     * as an issueHandler element, rather than assuming from a Psalm major.
     */
    private function skipIfExplicitTaintedLlmPromptHandlerUnsupported(): void
    {
        try {
            $this->loadConfig('<issueHandlers><TaintedLlmPrompt errorLevel="error" /></issueHandlers>');
        } catch (\Psalm\Exception\ConfigException) {
            self::markTestSkipped('Installed Psalm has no TaintedLlmPrompt issue type (see docs/config.md#findpromptinjection).');
        }
    }
}
