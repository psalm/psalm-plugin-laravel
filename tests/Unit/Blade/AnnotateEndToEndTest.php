<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotateRequest;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationCollector;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationWriter;
use Psalm\LaravelPlugin\Blade\Annotate\TemplateAnnotator;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof that the annotate pass declares a template's variables from the types the
 * `view()` call sites pass. A real `vendor/bin/psalm` run is the only way to pin it: the producer
 * types exist only during an analysis, which is the whole reason the codemod is driven by one.
 *
 * The command half (argument forwarding, output handling) is covered by
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Cli\AnnotateCommandTest}; what it ultimately does is set
 * the environment variable this test sets by hand, against a fixture that is not a project root
 * and so has no `vendor/bin/psalm` of its own.
 *
 * The child runs with `--threads=2` to keep the plugin's analysis pin honest: the fixture has enough
 * files that Psalm forks the analysis when threads > 1, so without the pin the writer publishes its
 * 'forked workers' error and every annotation assertion fails.
 */
#[CoversClass(AnnotationCollector::class)]
#[CoversClass(AnnotationWriter::class)]
#[CoversClass(TemplateAnnotator::class)]
#[CoversClass(AnnotateRequest::class)]
#[Group('subprocess')]
final class AnnotateEndToEndTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/Annotate';

    private const VIEWS = self::FIXTURE . '/resources/views';

    private const TEMPLATES = [
        'home' => "<h1>{{ \$title }}</h1>\n<p>{{ \$post->slug }}</p>\n",
        'declared' => "<?php /** @var string \$title */ ?>\n<h1>{{ \$title }}</h1>\n<p>{{ \$extra }}</p>\n",
        'headed' => "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Header.\n */\n?>\n<h1>{{ \$title }}</h1>\n",
        'strict' => "<?php declare(strict_types=1); ?>\n<h1>{{ \$title }}</h1>\n",
        'note' => "<p>{{ \$note ?? '' }}</p>\n",
        'conflict' => "<p>{{ \$flag }}</p>\n",
        'loop' => "@foreach (\$items as \$item)\n  <li>{{ \$item }} {{ \$loop->index }}</li>\n@endforeach\n",
        'collections' => "{{ \$missing->count() }} {{ count(\$byRole) }} {{ \$kind }}\n",
        'panel' => "{{ \$family->count() }} {{ \$handler instanceof \\Closure }}\n",
        'panel-kind' => "{{ \$kind }}\n",
        'locals' => "@php(\$heading = 'Hello')\n<h1>{{ \$heading }}</h1>\n"
            . "<p>{{ implode(',', array_map(fn (\$item) => \$item . \$heading, \$rows)) }}</p>\n",
        'statics' => "<p>{{ \$stats['total'] }}</p>\n",
        'outer' => "<?php\n/** @var true|null \$showCount = null */\n\$showCount ??= false;\n?>\n"
            . "@includeIsolated('inner', ['showCount' => \$showCount])\n@includeIsolated('counter', ['showCount' => \$showCount])\n",
        'inner' => "<p>{{ \$showCount ? 'yes' : 'no' }}</p>\n",
        'counter' => "<p>{{ \$showCount ? 'yes' : 'no' }}</p>\n",
    ];

    private ?string $controlFile = null;

    /** Psalm's JSON report from the last run. */
    private string $psalmOutput = '';

    protected function setUp(): void
    {
        $this->clean();

        foreach (self::TEMPLATES as $name => $contents) {
            \file_put_contents(self::VIEWS . "/{$name}.blade.php", $contents);
        }
    }

    protected function tearDown(): void
    {
        $this->clean();

        if ($this->controlFile !== null) {
            @\unlink($this->controlFile);
            $this->controlFile = null;
        }
    }

    #[Test]
    public function declares_the_type_every_call_site_agreed_on(): void
    {
        $this->annotate();

        $this->assertSame(
            "<?php\n/**\n * @var \\AnnotateFixture\\Post \$post\n * @var string \$title\n */\n?>\n" . self::TEMPLATES['home'],
            $this->template('home'),
        );
    }

    #[Test]
    public function lists_rather_than_declares_a_name_two_call_sites_disagree_on(): void
    {
        $result = $this->annotate();

        $this->assertSame(self::TEMPLATES['conflict'], $this->template('conflict'));
        $this->assertSame(['flag'], $this->unknownFor($result, 'conflict'));
    }

    #[Test]
    public function appends_to_an_existing_one_line_docblock_without_touching_its_declaration(): void
    {
        $this->annotate();

        $this->assertSame(
            "<?php /**\n * @var string \$title\n * @var string \$extra\n */ ?>\n<h1>{{ \$title }}</h1>\n<p>{{ \$extra }}</p>\n",
            $this->template('declared'),
        );
    }

    #[Test]
    public function prints_an_unbounded_int_range_as_int(): void
    {
        $this->annotate();

        $this->assertSame("<?php\n/**\n * @var array{total: int} \$stats\n */\n?>\n" . self::TEMPLATES['statics'], $this->template('statics'));
    }

    #[Test]
    public function an_include_site_counts_as_a_producer_of_the_included_view(): void
    {
        // The include passes `bool` (the flow type after `??= false`), the controller `true`: they
        // disagree, so declaring the controller's type would be a type error at the include.
        $result = $this->annotate();

        $this->assertSame(self::TEMPLATES['inner'], $this->template('inner'));
        $this->assertSame(['showCount'], $this->unknownFor($result, 'inner'));

        // Where the controller passes `bool` too, the two agree: the include's type is the one
        // after the reassignment, not the including template's declared `true|null`.
        $this->assertSame("<?php\n/**\n * @var bool \$showCount\n */\n?>\n" . self::TEMPLATES['counter'], $this->template('counter'));
    }

    #[Test]
    public function appends_to_the_header_docblock_of_a_template_that_has_one(): void
    {
        $this->annotate();

        $this->assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Header.\n * @var string \$title\n */\n?>\n<h1>{{ \$title }}</h1>\n",
            $this->template('headed'),
        );
    }

    #[Test]
    public function adds_a_block_after_the_declare_so_it_stays_the_first_statement(): void
    {
        $this->annotate();

        $this->assertSame(
            "<?php declare(strict_types=1); ?>\n<?php\n/**\n * @var string \$title\n */\n?>\n<h1>{{ \$title }}</h1>\n",
            $this->template('strict'),
        );
    }

    #[Test]
    public function declares_a_key_a_closed_call_site_omits_as_nullable_with_an_equals_null_tail(): void
    {
        $this->annotate();

        $this->assertSame(
            "<?php\n/**\n * @var string|null \$note = null\n */\n?>\n" . self::TEMPLATES['note'],
            $this->template('note'),
        );
    }

    #[Test]
    public function never_declares_a_loop_alias_the_template_binds_itself(): void
    {
        // A declared `$item` makes every correct call site report MissingViewVariable: the alias is
        // the template's own, not something the caller is expected to pass.
        $this->annotate();

        $this->assertSame(
            "<?php\n/**\n * @var list<string> \$items\n */\n?>\n" . self::TEMPLATES['loop'],
            $this->template('loop'),
        );
    }

    #[Test]
    public function never_declares_a_name_the_template_binds_for_itself(): void
    {
        // `@php($heading = ...)` and a closure parameter are template locals, not inputs: declaring
        // either reports MissingViewVariable at every correct call site. The only call site is one the
        // plugin cannot read, so `$rows` is listed, not declared `mixed`.
        $result = $this->annotate(dryRun: true);

        $this->assertSame(self::TEMPLATES['locals'], $this->template('locals'));
        $this->assertSame(['rows'], $this->unknownFor($result, 'locals'));
        $this->assertStringNotContainsString('locals.blade.php', (string) ($result['diff'] ?? ''));
    }

    #[Test]
    public function declares_a_collection_without_the_late_static_marker(): void
    {
        // A template has no class scope, so `&static` would bind to nothing. It must also not make
        // a `static` Collection and a plain one disagree about the same name.
        $result = $this->annotate();

        $this->assertSame(
            "<?php\n/**\n * @var array<int<1, max>, \\Illuminate\\Support\\Collection<int, string>> \$byRole\n"
            . " * @var class-string<\\Illuminate\\Support\\Collection<int<0, 1>, string>> \$kind\n"
            . " * @var \\Illuminate\\Support\\Collection<int<0, 1>, string> \$missing\n */\n?>\n" . self::TEMPLATES['collections'],
            $this->template('collections'),
        );
        $this->assertNull($this->unknownFor($result, 'collections'), 'a template with nothing unknown has no entry');
    }

    #[Test]
    public function leaves_undeclared_a_late_static_marker_it_cannot_drop_and_a_template_behind_get_class(): void
    {
        // `Collection<int, Panel>` contradicts a `Collection<int, Panel&static>` caller in the
        // invariant TValue slot, and `Closure(Panel): void` promises to accept more than the caller's
        // `Closure(Panel&static): void`. `get_class()` of a `Collection<int, T>` would write `T` as a
        // class. None of them has a declaration, so each is listed instead.
        $result = $this->annotate();

        $this->assertSame(self::TEMPLATES['panel-kind'], $this->template('panel-kind'));
        $this->assertSame(['kind'], $this->unknownFor($result, 'panel-kind'));
        $this->assertSame(self::TEMPLATES['panel'], $this->template('panel'));
        $this->assertEqualsCanonicalizing(['family', 'handler'], $this->unknownFor($result, 'panel'));
    }

    #[Test]
    public function refuses_a_control_file_this_cli_did_not_write(): void
    {
        // A leaked PSALM_LARAVEL_BLADE_ANNOTATE (a stale shell, a .envrc, a CI export) must not turn
        // an ordinary psalm run into a codemod, nor overwrite whatever the variable happens to name.
        $decoy = \tempnam(\sys_get_temp_dir(), 'psalm-laravel-annotate-decoy');
        $this->assertIsString($decoy);
        $this->controlFile = $decoy;

        $contents = (string) \json_encode(['name' => 'acme/project', 'require' => ['php' => '^8.2']]);
        \file_put_contents($decoy, $contents);

        $this->runPsalm($decoy);

        $this->assertSame($contents, \file_get_contents($decoy), 'the named file must be left intact');

        foreach (self::TEMPLATES as $name => $template) {
            $this->assertSame($template, $this->template($name), "{$name} must be left alone");
        }
    }

    #[Test]
    public function a_second_run_over_annotated_templates_changes_nothing(): void
    {
        $this->annotate();
        $fingerprints = \array_map(fn(string $name): string => \md5($this->template($name)), \array_keys(self::TEMPLATES));

        $this->annotate();

        $this->assertSame(
            $fingerprints,
            \array_map(fn(string $name): string => \md5($this->template($name)), \array_keys(self::TEMPLATES)),
        );
    }

    #[Test]
    public function reports_the_diff_without_writing_under_dry_run(): void
    {
        $result = $this->annotate(dryRun: true);

        $this->assertSame(self::TEMPLATES['home'], $this->template('home'), 'the template must be left alone');
        $this->assertIsArray($result['changed'] ?? null);
        $this->assertCount(9, $result['changed']);
        $this->assertStringContainsString('+ * @var string $title', (string) ($result['diff'] ?? ''));
    }

    #[Test]
    public function an_ordinary_psalm_run_never_writes_to_a_template(): void
    {
        // The environment gate is the whole reason a plugin that can write is safe to ship.
        $this->runPsalm(null);

        foreach (self::TEMPLATES as $name => $contents) {
            $this->assertSame($contents, $this->template($name));
        }
    }

    #[Test]
    public function skips_taint_analysis_in_annotate_mode(): void
    {
        // The codemod reads call-site types only; the taint graph Psalm 7 builds by default is
        // pure cost there. The ordinary run is the positive control: the fixture has a taint flow.
        $this->runPsalm(null);
        $this->assertStringContainsString('"type":"TaintedHtml"', $this->psalmOutput);

        $this->annotate(dryRun: true);
        $this->assertStringNotContainsString('"type":"Tainted', $this->psalmOutput);
    }

    /** @return array<string, mixed> the control file's result */
    private function annotate(bool $dryRun = false): array
    {
        $controlFile = \tempnam(\sys_get_temp_dir(), 'psalm-laravel-annotate-e2e');
        $this->assertIsString($controlFile);
        $this->controlFile = $controlFile;

        \file_put_contents($controlFile, (string) \json_encode([
            AnnotateRequest::MARKER => 1,
            'dryRun' => $dryRun,
        ]));

        $this->runPsalm($controlFile);

        $decoded = \json_decode((string) \file_get_contents($controlFile), true);
        $this->assertIsArray($decoded, 'the annotate pass never reported back');

        return $decoded;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return mixed the names listed as type-unknown for one template
     */
    private function unknownFor(array $result, string $name): mixed
    {
        $this->assertIsArray($result['unknown'] ?? null);

        foreach ($result['unknown'] as $path => $names) {
            if (\str_ends_with((string) $path, "/{$name}.blade.php")) {
                return $names;
            }
        }

        return null;
    }

    private function runPsalm(?string $controlFile): void
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=2', '--no-progress', '--output-format=json'],
            self::FIXTURE,
            $controlFile === null ? null : [AnnotateRequest::ENV_VAR => $controlFile],
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports issues of its own at errorLevel 1.
        $process->run();

        $this->psalmOutput = $process->getOutput();
    }

    private function template(string $name): string
    {
        return (string) \file_get_contents(self::VIEWS . "/{$name}.blade.php");
    }

    private function clean(): void
    {
        foreach (\glob(self::VIEWS . '/*.blade.php') ?: [] as $template) {
            \unlink($template);
        }

        foreach (\glob(self::FIXTURE . '/.cache/*/*') ?: [] as $cached) {
            \unlink($cached);
        }
    }
}
