<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
    public function plans_mixed_for_a_read_variable_no_producer_could_type(): void
    {
        $plan = AnnotationWriter::plan('home', new ViewDataContract([], false, ['title'], false));

        $this->assertSame(['title' => 'mixed'], $plan);
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

        $this->assertSame(['body' => 'mixed'], AnnotationWriter::plan('home', $contract));
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
        $this->assertSame("{{-- @var string \$title --}}\n<h1>{{ \$title }}</h1>\n", \file_get_contents($path));
    }

    #[Test]
    public function leaves_the_template_untouched_under_dry_run_but_still_reports_the_diff(): void
    {
        $path = $this->template("<h1>{{ \$title }}</h1>\n");
        $before = \md5_file($path);

        $diff = AnnotationWriter::apply($path, ['title' => 'string'], true);

        $this->assertSame($before, \md5_file($path));
        $this->assertNotNull($diff);
        $this->assertStringContainsString('@@ -1 +1,2 @@', $diff);
        $this->assertStringContainsString("+{{-- @var string \$title --}}", $diff);
    }

    #[Test]
    public function a_second_write_of_the_same_plan_changes_nothing(): void
    {
        $path = $this->template("<h1>{{ \$title }}</h1>\n");

        AnnotationWriter::apply($path, ['title' => 'string'], false);
        $afterFirst = \md5_file($path);

        $this->assertNull(AnnotationWriter::apply($path, ['title' => 'string'], false));
        $this->assertSame($afterFirst, \md5_file($path));
    }

    #[Test]
    public function reports_a_template_it_cannot_read_rather_than_failing_silently(): void
    {
        $this->expectException(\RuntimeException::class);

        AnnotationWriter::apply($this->tempDir . \DIRECTORY_SEPARATOR . 'absent.blade.php', ['t' => 'string'], true);
    }

    #[Test]
    public function refuses_to_write_when_the_run_never_analysed_a_statement(): void
    {
        // Psalm forks its analysis workers once the project is big enough, and a worker's statics
        // never reach the parent that runs AfterAnalysis. Writing then would declare `mixed` for
        // every variable of every template, silently, with exit code 0.
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

        $this->assertSame("{{-- @var string \$title --}}\n<h1>{{ \$title }}</h1>\n", \file_get_contents($path));
        $this->assertArrayNotHasKey('error', $this->publishedResult());
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
    public function leaves_out_a_name_a_provably_closed_call_site_does_not_pass(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', [], true);

        $this->assertSame([], AnnotationWriter::plan('home', new ViewDataContract([], false, ['title'], false)));
    }

    #[Test]
    public function the_hunk_header_normalises_a_windows_style_path_to_forward_slashes(): void
    {
        // No Windows CI here; this pins the normalization at the unit level. Outside any project
        // root, so it also exercises the absolute-path fallback.
        $method = new \ReflectionMethod(AnnotationWriter::class, 'headerPath');

        $this->assertSame(
            'C:/Users/dev/project/resources/views/page.blade.php',
            $method->invoke(null, 'C:\\Users\\dev\\project\\resources\\views\\page.blade.php'),
        );
    }

    #[Test]
    public function the_dry_run_diff_for_an_unterminated_contract_line_matches_the_actual_byte_change(): void
    {
        // The template ends ON its existing contract comment with no trailing newline. Declaring a
        // second name inserts a line break the file did not have, which modifies that final line —
        // a hand-rolled "pure insertion" hunk header would lie about that.
        $path = $this->template('{{-- @var string $title --}}');
        $before = (string) \file_get_contents($path);

        $diff = $this->applyFromProjectRoot(fn(): ?string => AnnotationWriter::apply($path, ['title' => 'string', 'body' => 'string'], true));
        $this->assertNotNull($diff);

        AnnotationWriter::apply($path, ['title' => 'string', 'body' => 'string'], false);
        $actual = (string) \file_get_contents($path);

        \file_put_contents($path, $before);
        $this->applyWithGitApply($diff);

        $this->assertSame($actual, \file_get_contents($path), 'applying the dry-run diff must reproduce the real write byte for byte');
    }

    #[Test]
    public function the_dry_run_diff_applies_cleanly_with_plain_git_apply_from_the_project_root(): void
    {
        $path = $this->template("<h1>{{ \$title }}</h1>\n");
        $before = (string) \file_get_contents($path);

        $diff = $this->applyFromProjectRoot(fn(): ?string => AnnotationWriter::apply($path, ['title' => 'string'], true));
        $this->assertNotNull($diff);

        AnnotationWriter::apply($path, ['title' => 'string'], false);
        $actual = (string) \file_get_contents($path);

        \file_put_contents($path, $before);
        $this->applyWithGitApply($diff);

        $this->assertSame($actual, \file_get_contents($path));
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

    /** A relative header lets `git apply` use its default single-component strip from the project root — no `--unsafe-paths`, no forcing the process to `/`. */
    private function applyWithGitApply(string $diff): void
    {
        $process = new Process(['git', 'apply'], $this->tempDir, null, $diff);
        $process->mustRun();
    }

    private function registerTemplate(string $contents): string
    {
        $path = $this->template($contents);

        ViewReferenceRegistry::registerTemplate('page', 0, $path);
        ContractRegistry::register('page', 0, new ViewDataContract([], false, ['title'], false));

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
}
