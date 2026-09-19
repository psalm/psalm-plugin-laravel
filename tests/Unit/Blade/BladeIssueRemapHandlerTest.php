<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\CodeLocation\Raw;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\UndefinedVariable;
use Psalm\LaravelPlugin\Blade\BladeIssueRemapHandler;
use Psalm\LaravelPlugin\Blade\ShadowEntry;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

/**
 * The decline paths only. Every path past them calls `IssueBuffer::accepts()`, which needs a live
 * `ProjectAnalyzer` — so each test here also pins that the handler bails BEFORE reaching it, and
 * the re-emission itself is covered end to end by {@see BladeIssueRemapTest}.
 */
#[CoversClass(BladeIssueRemapHandler::class)]
final class BladeIssueRemapHandlerTest extends TestCase
{
    private const SHADOW = '/tmp/blade-shadows/shadow.php';

    private string $templatePath;

    protected function setUp(): void
    {
        ShadowRegistry::reset();
        BladeIssueRemapHandler::reset();

        $this->templatePath = \tempnam(\sys_get_temp_dir(), 'blade-remap') ?: '';
        \file_put_contents($this->templatePath, "<div>\n  <p>body</p>\n</div>\n");
    }

    protected function tearDown(): void
    {
        ShadowRegistry::reset();
        BladeIssueRemapHandler::reset();

        @\unlink($this->templatePath);
    }

    private function registerShadow(?string $templatePath = null): void
    {
        ShadowRegistry::register(self::SHADOW, new ShadowEntry($templatePath ?? $this->templatePath, [1 => 2], []));
    }

    private function event(CodeIssue $issue, ?TaintFlowGraph $taintFlowGraph): BeforeAddIssueEvent
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->taint_flow_graph = $taintFlowGraph;

        return new BeforeAddIssueEvent($issue, false, $codebase);
    }

    private function issueOn(string $filePath): UndefinedVariable
    {
        return new UndefinedVariable('Cannot find referenced variable $x', new Raw('', $filePath, 'x.php', 0, 0));
    }

    #[Test]
    public function it_passes_through_an_issue_from_a_file_that_is_not_a_shadow(): void
    {
        $this->registerShadow();

        $this->assertNull(BladeIssueRemapHandler::beforeAddIssue(
            $this->event($this->issueOn('/app/src/Controller.php'), null),
        ));
    }

    #[Test]
    public function it_passes_through_a_reentrant_issue(): void
    {
        $this->registerShadow();

        // What the handler sees while its own re-emission is in flight. The structural guard (the
        // re-emitted issue lives on the template path, which is never a registry key) already
        // covers this; the flag is what keeps it true if templates ever become registry keys.
        (new \ReflectionProperty(BladeIssueRemapHandler::class, 'remapping'))->setValue(null, true);

        $this->assertNull(BladeIssueRemapHandler::beforeAddIssue(
            $this->event($this->issueOn(self::SHADOW), null),
        ));
    }

    #[Test]
    public function a_taint_flow_graph_is_not_a_decline_reason(): void
    {
        // Psalm 7 runs taint by default and emits type and taint issues from the same run, so a
        // non-taint issue on a shadow still has to be relocated. (Psalm 6 runs taint exclusively and
        // discards non-Tainted* issues under a graph, which is why 3.x declines here instead.)
        // Proof that nothing short-circuits on the graph: the handler runs past it into target
        // resolution, which reaches for the live Config this bare harness deliberately has none of.
        $this->registerShadow();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('No config initialized');

        BladeIssueRemapHandler::beforeAddIssue(
            $this->event($this->issueOn(self::SHADOW), new TaintFlowGraph()),
        );
    }

    #[Test]
    public function it_declines_when_the_template_can_no_longer_be_read(): void
    {
        $this->registerShadow($this->templatePath . '-deleted');

        $this->assertNull(BladeIssueRemapHandler::beforeAddIssue(
            $this->event($this->issueOn(self::SHADOW), null),
        ));
    }
}
