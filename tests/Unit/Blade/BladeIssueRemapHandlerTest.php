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
use Psalm\Issue\TaintedHtml;
use Psalm\Issue\UndefinedVariable;
use Psalm\LaravelPlugin\Blade\BladeIssueRemapHandler;
use Psalm\LaravelPlugin\Blade\ShadowEntry;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

/**
 * The decline paths only, for both branches `beforeAddIssue()` can take: the shadow-primary branch
 * (`remapShadowIssue()`) and the journey-only branch for a taint finding whose sink is ordinary
 * application code (`remapJourney()`, #1519). Every path past them calls `IssueBuffer::accepts()`,
 * which needs a live `ProjectAnalyzer` — so each test here also pins that the handler bails BEFORE
 * reaching it, and the re-emission itself is covered end to end by {@see BladeIssueRemapTest}.
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
    public function it_declines_a_taint_issue_whose_journey_never_touches_a_shadow(): void
    {
        // #1519: the journey-only branch only fires when a hop actually crossed a template. A
        // taint finding entirely inside ordinary application code must be left for Psalm.
        $this->registerShadow();

        $location = new Raw('', '/app/src/Controller.php', 'app/src/Controller.php', 0, 0);
        $issue = new TaintedHtml('Detected tainted HTML', $location, [
            ['location' => $location, 'label' => 'call to echo', 'entry_path_type' => ''],
        ], 'call to echo (app/src/Controller.php:1:1)');

        $this->assertNull(BladeIssueRemapHandler::beforeAddIssue($this->event($issue, null)));
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
    public function it_declines_when_the_template_can_no_longer_be_read(): void
    {
        $this->registerShadow($this->templatePath . '-deleted');

        $this->assertNull(BladeIssueRemapHandler::beforeAddIssue(
            $this->event($this->issueOn(self::SHADOW), null),
        ));
    }

    /**
     * A shadow that cannot be loaded yet answers "no suppressions" for that call only: caching the empty
     * answer would hide a suppression the same shadow carries once it can be read.
     */
    #[Test]
    public function a_failed_statement_load_is_not_cached(): void
    {
        $fileProvider = new \Psalm\Internal\Provider\FileProvider();
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        // Initialised from the class's own scope: some of these properties are readonly or `protected(set)`.
        \Closure::bind(function () use ($fileProvider): void {
            $this->statements_provider = new \Psalm\Internal\Provider\StatementsProvider($fileProvider);
            $this->file_reference_provider = new \Psalm\Internal\Provider\FileReferenceProvider($fileProvider);
            $this->progress = new \Psalm\Progress\VoidProgress();
            $this->analysis_php_version_id = \PHP_VERSION_ID;
            $this->diff_methods = false;
            $this->diff_run = false;
            $this->language_server = false;
        }, $codebase, Codebase::class)();

        $shadow = \sys_get_temp_dir() . '/blade-remap-late-' . \uniqid() . '.php';
        $nodes = new \ReflectionMethod(BladeIssueRemapHandler::class, 'suppressingNodes');

        // Parsing reads the config singleton; a bare one is enough.
        $config = new \ReflectionClass(\Psalm\Config::class);
        $instance = $config->newInstanceWithoutConstructor();
        $config->getProperty('eventDispatcher')->setValue($instance, new \Psalm\Internal\EventDispatcher());
        $config->getProperty('instance')->setValue(null, $instance);

        try {
            $this->assertSame([], $nodes->invoke(null, $codebase, $shadow));

            \file_put_contents($shadow, "<?php\n/** @psalm-suppress UndefinedVariable */\necho \$x;\n");

            $this->assertCount(1, $nodes->invoke(null, $codebase, $shadow));
        } finally {
            $config->getProperty('instance')->setValue(null, null);
            @\unlink($shadow);
        }
    }
}
