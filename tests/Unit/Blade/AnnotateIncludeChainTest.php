<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotateRequest;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationCollector;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

/**
 * One annotate run types a whole `@includeIsolated` chain: the child takes the type its including
 * template is declared with in the same run. Every case reads the same two runs over a private copy
 * of `Fixtures/AnnotateIncludes`: a dry run, then a writing run.
 *
 * The expected templates are the fixpoint that rerunning the command until nothing changes reaches,
 * except the declines, which a rerun may still type differently and so stay undeclared.
 */
#[CoversClass(AnnotationCollector::class)]
#[Group('subprocess')]
final class AnnotateIncludeChainTest extends TestCase
{
    use CopiesFixtureDirectories;

    private const POST = '\\AnnotateIncludesFixture\\Post';

    private static ?string $copy = null;

    /** @var array{dry: array<string, mixed>, written: array<string, mixed>, before: array<string, string>, after: array<string, string>}|null */
    private static ?array $runs = null;

    protected function setUp(): void
    {
        // Below Laravel 12.47 `@includeIsolated` is no directive: the includes stay text.
        if (!\method_exists(BladeCompiler::class, 'compileIncludeIsolated')) {
            $this->markTestSkipped('@includeIsolated needs Laravel 12.47+');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$copy !== null) {
            self::removeDirectory(self::$copy);
        }

        self::$copy = null;
        self::$runs = null;
    }

    #[Test]
    public function types_every_template_along_the_include_graph_in_one_run(): void
    {
        $runs = $this->runs();
        $declare = static fn(string $type, string $name, string $tail = ''): string
            => "<?php\n/**\n * @var {$type} \${$name}{$tail}\n */\n?>\n";
        $optional = self::POST . '|null';

        $expected = [
            // Three levels, renaming the key on the last hop.
            'chain-top' => $declare(self::POST, 'member'),
            'chain-mid' => $declare(self::POST, 'member'),
            'chain-leaf' => $declare(self::POST, 'entry'),
            // A parent declared `= null` hands on `T|null`; the include always passes the key.
            'opt-top' => $declare($optional, 'member', ' = null'),
            'opt-leaf' => $declare($optional, 'member'),
            'narrow-top' => $declare($optional, 'member', ' = null'),
            'narrow-plain' => $declare($optional, 'member'),
            'isset-top' => $declare($optional, 'member', ' = null'),
            'coalesce-top' => $declare($optional, 'x', ' = null'),
            'spread-top' => $declare(self::POST, 'member'),
        ];

        foreach ($runs['before'] as $name => $source) {
            // Declined, and left as they were: a cycle; an include under `$member !== null`, under
            // `@isset`, after `??=`, or before a spread that may overwrite it (each narrows or replaces
            // what the parent declares); a loop alias; a parent that reads names it cannot list; a parent whose call sites disagree.
            $this->assertSame(($expected[$name] ?? '') . $source, $runs['after'][$name] ?? null, $name);
        }
    }

    /** `mixed` from a parent that plans no type is a producer too: the leaf's own `string` does not win. */
    #[Test]
    public function counts_an_include_from_an_untyped_parent_as_passing_mixed(): void
    {
        $disagreed = (array) ($this->runs()['written']['disagreed'] ?? []);

        $this->assertSame(['member' => ['int', 'string']], $disagreed['split-top'] ?? null);
        $this->assertSame(['member' => ['mixed', 'string']], $disagreed['split-leaf'] ?? null);
    }

    #[Test]
    public function prints_under_dry_run_the_diff_the_writing_run_applies(): void
    {
        $runs = $this->runs();

        $this->assertStringContainsString('+ * @var ' . self::POST . ' $entry', (string) ($runs['dry']['diff'] ?? ''));
        $this->assertSame($runs['dry']['diff'] ?? null, $runs['written']['diff'] ?? null);
    }

    /**
     * @return array{dry: array<string, mixed>, written: array<string, mixed>, before: array<string, string>, after: array<string, string>}
     */
    private function runs(): array
    {
        if (self::$runs !== null) {
            return self::$runs;
        }

        // realpath(): Psalm reports canonical paths, and macOS's temp dir is a symlink.
        $copy = \realpath(\sys_get_temp_dir()) . '/psalm-annotate-includes-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        self::copyDirectory(__DIR__ . '/Fixtures/AnnotateIncludes', $copy);
        self::$copy = $copy;

        $before = $this->templates($copy);
        $dry = $this->annotate($copy, true);
        $this->assertSame($before, $this->templates($copy), 'a dry run must write nothing');

        return self::$runs = [
            'dry' => $dry,
            'written' => $this->annotate($copy, false),
            'before' => $before,
            'after' => $this->templates($copy),
        ];
    }

    /** @return array<string, mixed> the writer's result, as reported through the control file */
    private function annotate(string $copy, bool $dryRun): array
    {
        $psalm = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $control = \tempnam(\sys_get_temp_dir(), 'psalm-laravel-annotate-includes');
        $this->assertIsString($control);
        \file_put_contents($control, (string) \json_encode([AnnotateRequest::MARKER => 1, 'dryRun' => $dryRun]));

        try {
            // `--threads=2` keeps the plugin's single-process analysis pin honest, as in RunsAnnotateFixture.
            $process = new Process(
                [\PHP_BINARY, $psalm, '--no-cache', '--threads=2', '--no-progress', '--output-format=json'],
                $copy,
                [AnnotateRequest::ENV_VAR => $control],
            );
            $process->setTimeout(300);
            $process->run();

            $result = \json_decode((string) \file_get_contents($control), true);
            $this->assertIsArray($result, "the annotate pass never reported back\n" . $process->getErrorOutput());

            return $result;
        } finally {
            @\unlink($control);
        }
    }

    /** @return array<string, string> */
    private function templates(string $copy): array
    {
        $templates = [];

        foreach (\glob($copy . '/resources/views/*.blade.php') ?: [] as $path) {
            $templates[\basename($path, '.blade.php')] = (string) \file_get_contents($path);
        }

        return $templates;
    }
}
