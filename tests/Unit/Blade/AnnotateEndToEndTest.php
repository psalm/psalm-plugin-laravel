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
 * The command half (argument forwarding, the forced thread count) is covered by
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Cli\AnnotateCommandTest}; what it ultimately does is set
 * the environment variable this test sets by hand, against a fixture that is not a project root
 * and so has no `vendor/bin/psalm` of its own.
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
        'declared' => "{{-- @var string \$title --}}\n<h1>{{ \$title }}</h1>\n<p>{{ \$extra }}</p>\n",
        'headed' => "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Header.\n */\n?>\n<h1>{{ \$title }}</h1>\n",
        'strict' => "<?php declare(strict_types=1); ?>\n<h1>{{ \$title }}</h1>\n",
        'conflict' => "<p>{{ \$flag }}</p>\n",
        'loop' => "@foreach (\$items as \$item)\n  <li>{{ \$item }} {{ \$loop->index }}</li>\n@endforeach\n",
        'locals' => "@php(\$heading = 'Hello')\n<h1>{{ \$heading }}</h1>\n"
            . "<p>{{ implode(',', array_map(fn (\$item) => \$item . \$heading, \$rows)) }}</p>\n",
    ];

    private ?string $controlFile = null;

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
            "<?php\n/**\n * @var AnnotateFixture\\Post \$post\n * @var string \$title\n */\n?>\n" . self::TEMPLATES['home'],
            $this->template('home'),
        );
    }

    #[Test]
    public function falls_back_to_mixed_when_two_call_sites_disagree(): void
    {
        $this->annotate();

        $this->assertSame("<?php\n/**\n * @var mixed \$flag\n */\n?>\n" . self::TEMPLATES['conflict'], $this->template('conflict'));
    }

    #[Test]
    public function leaves_an_existing_blade_comment_declaration_untouched(): void
    {
        $this->annotate();

        $this->assertSame(
            "<?php\n/**\n * @var string \$extra\n */\n?>\n" . self::TEMPLATES['declared'],
            $this->template('declared'),
        );
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
        // either reports MissingViewVariable at every correct call site.
        $this->annotate();

        $this->assertSame(
            "<?php\n/**\n * @var mixed \$rows\n */\n?>\n" . self::TEMPLATES['locals'],
            $this->template('locals'),
        );
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
        $this->assertCount(7, $result['changed']);
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

    private function runPsalm(?string $controlFile): void
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            self::FIXTURE,
            $controlFile === null ? null : [AnnotateRequest::ENV_VAR => $controlFile],
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports issues of its own at errorLevel 1.
        $process->run();
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
