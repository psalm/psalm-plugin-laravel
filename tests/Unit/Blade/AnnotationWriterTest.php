<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotateRequest;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationCollector;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationWriter;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ContractVar;
use Psalm\LaravelPlugin\Blade\ViewDataContract;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;
use Psalm\Type;
use Symfony\Component\Process\Process;

#[CoversClass(AnnotationWriter::class)]
final class AnnotationWriterTest extends TestCase
{
    private const DIAMOND_LEVELS = 20;

    private string $tempDir;

    protected function setUp(): void
    {
        AnnotationCollector::reset();
        AnnotationWriter::reset();
        ContractRegistry::reset();
        ViewReferenceRegistry::reset();

        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-annotate-' . \uniqid('', true);

        if (!\mkdir($this->tempDir) && !\is_dir($this->tempDir)) {
            throw new \RuntimeException("Failed to create temp directory {$this->tempDir}");
        }

        // macOS's system temp dir is reached through a `/var` -> `/private/var` symlink, which
        // `git apply`'s path-safety check refuses to traverse; the canonical path avoids that.
        $this->tempDir = (string) \realpath($this->tempDir);
    }

    protected function tearDown(): void
    {
        AnnotationCollector::reset();
        AnnotationWriter::reset();
        ContractRegistry::reset();
        ViewReferenceRegistry::reset();
        \putenv(AnnotateRequest::ENV_VAR);

        foreach (\glob($this->tempDir . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->tempDir);
    }

    #[Test]
    public function plans_the_resolved_type_for_a_read_variable_every_producer_agreed_on(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $plan = AnnotationWriter::plan('home', new ViewDataContract([], false, ['title'], false));

        $this->assertSame(['title' => 'string'], $plan);
    }

    #[Test]
    public function plans_no_type_for_a_read_variable_no_producer_could_type(): void
    {
        $plan = AnnotationWriter::plan('home', new ViewDataContract([], false, ['title'], false));

        $this->assertSame(['title' => null], $plan);
    }

    #[Test]
    public function leaves_out_a_variable_the_template_already_declares(): void
    {
        $contract = new ViewDataContract(
            ['title' => new ContractVar('title', 'string', 1, false)],
            false,
            ['title', 'body'],
            false,
        );

        $this->assertSame(['body' => null], AnnotationWriter::plan('home', $contract));
    }

    #[Test]
    public function skips_a_template_whose_compiled_body_hides_which_names_it_reads(): void
    {
        // `@props` and `@aware` compile to `$$name`; a partial read set must never be annotated.
        $this->assertSame([], AnnotationWriter::plan('home', new ViewDataContract([], false, ['title'], true)));
    }

    #[Test]
    public function writes_the_contract_block_into_the_template(): void
    {
        $path = $this->template("<h1>{{ \$title }}</h1>\n");

        $diff = AnnotationWriter::apply($path, ['title' => 'string'], false);

        $this->assertNotNull($diff);
        $this->assertSame("<?php\n/**\n * @var string \$title\n */\n?>\n<h1>{{ \$title }}</h1>\n", \file_get_contents($path));
    }

    #[Test]
    public function leaves_the_template_untouched_under_dry_run_but_still_reports_the_diff(): void
    {
        $path = $this->template("<h1>{{ \$title }}</h1>\n");
        $before = \md5_file($path);

        $diff = AnnotationWriter::apply($path, ['title' => 'string'], true);

        $this->assertSame($before, \md5_file($path));
        $this->assertNotNull($diff);
        $this->assertStringContainsString('@@ -1 +1,6 @@', $diff);
        $this->assertStringContainsString('+ * @var string $title', $diff);
    }

    #[Test]
    public function refuses_to_write_when_the_run_never_analysed_a_statement(): void
    {
        // Psalm forks its analysis workers once the project is big enough, and a worker's statics
        // never reach the parent that runs AfterAnalysis. Reporting then would call every variable
        // of every template untyped, with exit code 0.
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>\n");
        $before = \md5_file($path);

        AnnotationWriter::run($this->request());

        $this->assertSame($before, \md5_file($path));
        $this->assertIsString($this->publishedResult()['error'] ?? null, 'the run has to report why it wrote nothing');
    }

    #[Test]
    public function writes_once_the_run_did_analyse_statements(): void
    {
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>\n");
        AnnotationCollector::markAnalyzed();
        AnnotationCollector::record('page', ['title' => Type::getString()], true);

        AnnotationWriter::run($this->request());

        $this->assertSame("<?php\n/**\n * @var string \$title\n */\n?>\n<h1>{{ \$title }}</h1>\n", \file_get_contents($path));
        $this->assertArrayNotHasKey('error', $this->publishedResult());
    }

    #[Test]
    public function lists_instead_of_declaring_a_name_no_call_site_types(): void
    {
        // A `mixed` declaration adds nothing a reader can use and makes the name required at every
        // call site; with no typed name left, the template gets no docblock at all.
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>\n");
        AnnotationCollector::markAnalyzed();
        $before = \md5_file($path);

        AnnotationWriter::run($this->request());

        $this->assertSame($before, \md5_file($path));
        $result = $this->publishedResult();
        $this->assertSame([], $result['changed'] ?? null);
        $this->assertSame([$path => ['title']], $result['unknown'] ?? null);
    }

    #[Test]
    public function declares_the_typed_names_and_lists_the_rest(): void
    {
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>{{ \$body }}\n", ['title', 'body']);
        AnnotationCollector::markAnalyzed();
        AnnotationCollector::record('page', ['title' => Type::getString(), 'body' => Type::getMixed()], true);

        AnnotationWriter::run($this->request());

        $this->assertSame("<?php\n/**\n * @var string \$title\n */\n?>\n<h1>{{ \$title }}</h1>{{ \$body }}\n", \file_get_contents($path));
        $result = $this->publishedResult();
        $this->assertSame([$path => ['title']], $result['changed'] ?? null);
        $this->assertSame([$path => ['body']], $result['unknown'] ?? null);
    }

    #[Test]
    public function lists_a_name_its_call_sites_disagree_on_with_each_type_they_pass(): void
    {
        // "type unknown" would be wrong here: every call site types it, just not the same way.
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>{{ \$body }}\n", ['title', 'body']);
        AnnotationCollector::markAnalyzed();
        AnnotationCollector::record('page', ['title' => Type::getString(), 'body' => Type::getMixed()], true);
        AnnotationCollector::record('page', ['title' => Type::getInt(), 'body' => Type::getMixed()], true);
        $before = \md5_file($path);

        AnnotationWriter::run($this->request());

        $this->assertSame($before, \md5_file($path));
        $result = $this->publishedResult();
        $this->assertSame([$path => ['body']], $result['unknown'] ?? null);
        $this->assertSame(['page' => ['title' => ['int', 'string']]], $result['disagreed'] ?? null);
    }

    /**
     * One file, two names (a published override is `page` and `pkg::page`): a closed call site that
     * omits the name through EITHER renders the same file without it, so declaring it required would
     * report MissingViewData there.
     */
    #[Test]
    public function a_name_omitted_through_one_view_name_of_a_file_is_declared_optional(): void
    {
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>\n");
        ViewReferenceRegistry::registerTemplate('pkg::page', 0, $path);
        ContractRegistry::register('pkg::page', 0, new ViewDataContract([], false, ['title'], false));
        AnnotationCollector::markAnalyzed();
        AnnotationCollector::record('page', ['title' => Type::getString()], true);
        AnnotationCollector::record('page', [], true);
        AnnotationCollector::record('pkg::page', ['title' => Type::getString()], true);

        AnnotationWriter::run($this->request());

        $this->assertStringContainsString('@var string|null $title = null', (string) \file_get_contents($path));
    }

    /**
     * A published override is claimed under its default-root name (`vendor.pkg.page`) too, which a
     * project that only renders `pkg::page` never uses: a name nothing renders says nothing about
     * the file, so it must not veto what the name that is rendered proves.
     */
    #[Test]
    public function a_view_name_no_producer_renders_does_not_veto_the_name_that_is_rendered(): void
    {
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>\n");
        ViewReferenceRegistry::registerTemplate('pkg::page', 0, $path);
        ContractRegistry::register('pkg::page', 0, new ViewDataContract([], false, ['title'], false));
        AnnotationCollector::markAnalyzed();
        AnnotationCollector::record('pkg::page', ['title' => Type::getString()], true);

        AnnotationWriter::run($this->request());

        $this->assertStringContainsString('@var string $title', (string) \file_get_contents($path));
    }

    #[Test]
    public function an_alias_with_an_unreadable_producer_still_vetoes_the_name_that_is_rendered(): void
    {
        // The unreadable producer may be the one that renders this same file under the alias.
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>\n");
        ViewReferenceRegistry::registerTemplate('pkg::page', 0, $path);
        ContractRegistry::register('pkg::page', 0, new ViewDataContract([], false, ['title'], false));
        AnnotationCollector::markAnalyzed();
        AnnotationCollector::markUnreadable('page', '/app/A.php:3');
        AnnotationCollector::record('pkg::page', ['title' => Type::getString()], true);
        $before = \md5_file($path);

        AnnotationWriter::run($this->request());

        $this->assertSame($before, \md5_file($path));
    }

    /**
     * A file Psalm could not parse is a producer nobody can read: the views its string literals name
     * are left undeclared, and the file is reported.
     */
    #[Test]
    public function leaves_a_view_undeclared_when_a_file_that_names_it_has_a_parse_error(): void
    {
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>\n");
        $broken = $this->tempDir . \DIRECTORY_SEPARATOR . 'Broken.php';
        \file_put_contents($broken, "<?php\n\nreturn view(\n    'page',\n    ['title' => 42\n");
        AnnotationCollector::markAnalyzed();
        AnnotationCollector::record('page', ['title' => Type::getString()], true);
        $before = \md5_file($path);

        AnnotationWriter::run($this->request(), [$broken => [$this->parseError($broken, 5, 'Syntax error, unexpected EOF')]]);

        $this->assertSame($before, \md5_file($path));
        $result = $this->publishedResult();
        $this->assertSame([$path => ['site' => "{$broken}:4", 'names' => ['title']]], $result['unreadable'] ?? null);
        $this->assertSame([$broken => 'line 5: Syntax error, unexpected EOF'], $result['parseErrors'] ?? null);
    }

    #[Test]
    public function a_parse_error_in_a_file_naming_no_view_leaves_the_declarations_alone_but_is_still_reported(): void
    {
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>\n");
        $broken = $this->tempDir . \DIRECTORY_SEPARATOR . 'Broken.php';
        \file_put_contents($broken, "<?php\n\nreturn view(\n    'other',\n    ['title' => 42\n");
        AnnotationCollector::markAnalyzed();
        AnnotationCollector::record('page', ['title' => Type::getString()], true);

        AnnotationWriter::run($this->request(), [$broken => [$this->parseError($broken, 5, 'Syntax error, unexpected EOF')]]);

        $this->assertStringContainsString('@var string $title', (string) \file_get_contents($path));
        $this->assertSame([$broken => 'line 5: Syntax error, unexpected EOF'], $this->publishedResult()['parseErrors'] ?? null);
    }

    #[Test]
    public function refuses_to_write_when_the_control_file_is_no_longer_valid(): void
    {
        // The control file is named by an environment variable and can be repointed mid-run; the
        // templates are written well before publish() gets its own chance to re-check.
        $path = $this->registerTemplate("<h1>{{ \$title }}</h1>\n");
        AnnotationCollector::markAnalyzed();
        AnnotationCollector::record('page', ['title' => Type::getString()], true);
        $request = $this->request();
        $before = \md5_file($path);

        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'control.json', (string) \json_encode(['name' => 'acme']));

        AnnotationWriter::run($request);

        $this->assertSame($before, \md5_file($path));
    }

    #[Test]
    public function writes_an_optional_name_as_a_nullable_declaration_with_an_equals_null_tail_once(): void
    {
        $path = $this->template("<h1>{{ \$title }}</h1>\n");

        AnnotationWriter::apply($path, ['title' => 'string', 'n' => 'int|null', 'm' => 'mixed'], false, ['title' => true, 'n' => true, 'm' => true]);

        $this->assertSame(
            "<?php\n/**\n * @var mixed \$m = null\n * @var int|null \$n = null\n * @var string|null \$title = null\n */\n?>\n<h1>{{ \$title }}</h1>\n",
            \file_get_contents($path),
        );
        $this->assertNull(AnnotationWriter::apply($path, ['title' => 'string'], false, ['title' => true]), 'idempotent');
    }

    #[Test]
    public function the_dry_run_diff_for_an_opened_out_docblock_line_matches_the_actual_byte_change(): void
    {
        // A one-line header docblock is opened out: its text and the declaration each get a line,
        // and the `*/` moves to a line of its own. That modifies an existing line, so a hand-rolled
        // "pure insertion" hunk header would lie about it.
        $path = $this->template("<?php /** Header. */ ?>\n<p>{{ \$title }}</p>\n");
        $before = (string) \file_get_contents($path);

        $diff = $this->applyFromProjectRoot(fn(): ?string => AnnotationWriter::apply($path, ['title' => 'string'], true));
        $this->assertNotNull($diff);

        AnnotationWriter::apply($path, ['title' => 'string'], false);
        $actual = (string) \file_get_contents($path);

        \file_put_contents($path, $before);
        $this->applyWithGitApply($diff);

        $this->assertSame($actual, \file_get_contents($path), 'applying the dry-run diff must reproduce the real write byte for byte');
    }

    /**
     * Runs the callback with the current directory set to the fixture's own temp dir, standing in
     * for the project root: the `blade:annotate` CLI launches its child Psalm process with the
     * project root as that process's own working directory, which is what the header is meant to
     * be relative to.
     */
    private function applyFromProjectRoot(callable $callback): mixed
    {
        $previous = \getcwd();
        \chdir($this->tempDir);

        try {
            return $callback();
        } finally {
            if (\is_string($previous)) {
                \chdir($previous);
            }
        }
    }

    /**
     * Two templates per level, each including both templates of the next level with the same key:
     * every path down the diamonds is a distinct walk, 2^levels of them, to the same answer. Resolved
     * pair by pair once each, it takes a few milliseconds; resolved per path it does not finish.
     */
    #[Test]
    public function resolves_a_deep_diamond_of_isolated_includes_once_per_template(): void
    {
        $levels = self::DIAMOND_LEVELS;
        $names = [];

        foreach (\range(0, $levels - 1) as $level) {
            foreach (['a', 'b'] as $side) {
                $name = "l{$level}{$side}";
                $names[] = $name;
                ViewReferenceRegistry::registerTemplate($name, 0, "/virtual/{$name}.blade.php");
                ContractRegistry::register($name, 0, new ViewDataContract([], false, ['x'], false));

                if ($level === 0) {
                    AnnotationCollector::record($name, ['x' => Type::getString()], true);

                    continue;
                }

                foreach (['a', 'b'] as $parent) {
                    $parentName = 'l' . ($level - 1) . $parent;
                    AnnotationCollector::record($name, ['x' => Type::getMixed()], true, '', ['x' => ["/virtual/{$parentName}.blade.php", 'x']]);
                }
            }
        }

        $started = \hrtime(true);

        foreach (\array_reverse($names) as $name) {
            $this->assertSame('string', AnnotationCollector::typeFor($name, 'x'), $name);
        }

        $seconds = (\hrtime(true) - $started) / 1e9;
        $this->assertLessThan(2.0, $seconds, "resolving {$levels} levels of diamonds took {$seconds}s");
    }

    /**
     * A cycle's answer depends on which of its members the walk started from, so it is never kept:
     * asking for the downstream template first or last gives the same answer.
     */
    #[Test]
    public function answers_a_template_below_a_cycle_the_same_whichever_member_is_asked_first(): void
    {
        $answers = [];

        foreach ([['sink', 'down', 'up'], ['up', 'down', 'sink'], ['down', 'sink', 'up']] as $order) {
            AnnotationCollector::reset();

            foreach (['up', 'down', 'sink'] as $name) {
                ViewReferenceRegistry::registerTemplate($name, 0, "/virtual/{$name}.blade.php");
                ContractRegistry::register($name, 0, new ViewDataContract([], false, ['x'], false));
            }

            // up <-> down is a cycle; `up` also has a typed producer, and `sink` is included from `up`.
            AnnotationCollector::record('up', ['x' => Type::getString()], true);
            AnnotationCollector::record('up', ['x' => Type::getMixed()], true, '', ['x' => ['/virtual/down.blade.php', 'x']]);
            AnnotationCollector::record('down', ['x' => Type::getMixed()], true, '', ['x' => ['/virtual/up.blade.php', 'x']]);
            AnnotationCollector::record('sink', ['x' => Type::getMixed()], true, '', ['x' => ['/virtual/up.blade.php', 'x']]);

            $answered = [];

            foreach ($order as $name) {
                $answered[$name] = AnnotationCollector::typeFor($name, 'x');
            }

            \ksort($answered);
            $answers[] = $answered;
        }

        $this->assertSame(['down' => 'mixed', 'sink' => 'mixed', 'up' => null], $answers[0]);
        $this->assertSame($answers[0], $answers[1]);
        $this->assertSame($answers[0], $answers[2]);
    }

    /** The memo holds what the producers passed so far: a producer recorded later changes the answer. */
    #[Test]
    public function forgets_a_resolved_include_type_when_another_producer_is_recorded(): void
    {
        foreach (['parent', 'child'] as $name) {
            ViewReferenceRegistry::registerTemplate($name, 0, "/virtual/{$name}.blade.php");
            ContractRegistry::register($name, 0, new ViewDataContract([], false, ['x'], false));
        }

        AnnotationCollector::record('parent', ['x' => Type::getString()], true);
        AnnotationCollector::record('child', ['x' => Type::getMixed()], true, '', ['x' => ['/virtual/parent.blade.php', 'x']]);

        $this->assertSame('string', AnnotationCollector::typeFor('child', 'x'));

        AnnotationCollector::record('parent', ['x' => Type::getInt()], true);

        $this->assertSame('mixed', AnnotationCollector::typeFor('child', 'x'), 'the parent now disagrees with itself, so the include passes an unknown');
    }

    /** A relative header lets `git apply` use its default single-component strip from the project root — no `--unsafe-paths`, no forcing the process to `/`. */
    private function applyWithGitApply(string $diff): void
    {
        $process = new Process(['git', 'apply'], $this->tempDir, null, $diff);
        $process->mustRun();
    }

    /** @param list<string> $reads */
    private function registerTemplate(string $contents, array $reads = ['title']): string
    {
        $path = $this->template($contents);

        ViewReferenceRegistry::registerTemplate('page', 0, $path);
        ContractRegistry::register('page', 0, new ViewDataContract([], false, $reads, false));

        return $path;
    }

    private function request(): AnnotateRequest
    {
        $controlFile = $this->tempDir . \DIRECTORY_SEPARATOR . 'control.json';
        \file_put_contents($controlFile, (string) \json_encode(['psalm-laravel-annotate' => 1, 'dryRun' => false]));
        \putenv(AnnotateRequest::ENV_VAR . '=' . $controlFile);

        $request = AnnotateRequest::fromEnvironment();
        $this->assertInstanceOf(AnnotateRequest::class, $request);

        return $request;
    }

    /** @return array<string, mixed> */
    private function publishedResult(): array
    {
        $decoded = \json_decode((string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'control.json'), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function template(string $contents): string
    {
        $path = $this->tempDir . \DIRECTORY_SEPARATOR . 'page.blade.php';
        \file_put_contents($path, $contents);

        return $path;
    }

    private function parseError(string $file, int $line, string $message): IssueData
    {
        return new IssueData(IssueData::SEVERITY_ERROR, $line, $line, 'ParseError', $message, $file, $file, '', '', 0, 0, 0, 0, 1, 1);
    }
}
