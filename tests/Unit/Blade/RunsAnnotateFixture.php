<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Psalm\LaravelPlugin\Blade\Annotate\AnnotateRequest;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

/**
 * Real `vendor/bin/psalm` runs of the `Fixtures/Annotate` fixture, for the classes that pin the
 * annotate pass end to end.
 *
 * A run costs about six seconds whatever the assertion count, so a class asks {@see analyse()} for the
 * handful of distinct (mode, config) pairs it needs and reads its facts from the memoized report and
 * the template snapshot taken right after it. The fixture's templates are written fresh into a
 * private copy per class (and per paratest worker): the original is never analysed, so two classes
 * sharing it cannot wipe each other's templates or Psalm's caches mid-run.
 *
 * Both classes using this trait run the child with `--threads=2` to keep the plugin's analysis pin
 * honest: the fixture has enough files that Psalm forks the analysis when threads > 1, so without
 * the pin the writer publishes its 'forked workers' error and every annotation assertion fails.
 *
 * A using class must not declare its own `tearDownAfterClass()`: PHP silently prefers the class
 * method over the trait's, which would leak the fixture copy.
 *
 * @psalm-require-extends \PHPUnit\Framework\TestCase
 */
trait RunsAnnotateFixture
{
    use CopiesFixtureDirectories;

    /** Written into the copy's `resources/views` before the first run. */
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
        'merged' => "{{ json_encode([\$author, \$media, \$trio, \$flag, \$posts, \$any, \$key]) }}\n",
        'locals' => "@php(\$heading = 'Hello')\n<h1>{{ \$heading }}</h1>\n"
            . "<p>{{ implode(',', array_map(fn (\$item) => \$item . \$heading, \$rows)) }}</p>\n",
        'statics' => "<p>{{ \$stats['total'] }}</p>\n",
        'outer' => "<?php\n/** @var true|null \$showCount = null */\n\$showCount ??= false;\n?>\n"
            . "@includeIsolated('inner', ['showCount' => \$showCount])\n@includeIsolated('counter', ['showCount' => \$showCount])\n",
        'inner' => "<p>{{ \$showCount ? 'yes' : 'no' }}</p>\n",
        'counter' => "<p>{{ \$showCount ? 'yes' : 'no' }}</p>\n",
        'modal-arrow' => "{{ \$text }}\n",
        'modal-closure' => "{{ \$text }}\n",
        'modal-nested' => "{{ \$text }}\n",
        'modal-open' => "{{ \$text }}\n",
        'modal-rendered' => "{{ \$text }}\n",
        'modal-invoked' => "{{ \$text }}\n",
        'arrays' => "{{ count(\$labels) }} {{ count(\$params) }}\n",
        'scoped-host' => "<p>host</p>\n@include('scoped')\n",
        'scoped' => "{{ \$text }}\n",
        'pair-top' => "<p>{{ \$member->slug }}</p>\n@includeIsolated('pair-leaf', ['member' => \$member])\n",
        'pair-leaf' => "<p>{{ \$member->slug }}</p>\n",
        // Three levels, renaming the key on the last hop.
        'chain-top' => "<p>{{ \$member->slug }}</p>\n@includeIsolated('chain-mid', ['member' => \$member])\n",
        'chain-mid' => "@includeIsolated('chain-leaf', ['entry' => \$member])\n",
        'chain-leaf' => "<p>{{ \$entry->slug }}</p>\n",
        // One call site omits `$member`, so the parent is declared nullable.
        'opt-top' => "@includeIsolated('opt-leaf', ['member' => \$member])\n",
        'opt-leaf' => "<p>{{ \$member?->slug }}</p>\n",
        // A pure cycle: no template in it ever gets a type from the others.
        'cyc-a' => "<p>{{ \$x->slug }}</p>\n@includeIsolated('cyc-b', ['x' => \$x])\n",
        'cyc-b' => "@includeIsolated('cyc-a', ['x' => \$x])\n<p>{{ \$x->slug }}</p>\n",
        // `$member` is `Post|null` in the parent (one call site omits it). The plain include gets that;
        // the narrowed ones must wait: after the parent is declared, each narrows it to `Post`.
        'narrow-top' => "@includeIsolated('narrow-plain', ['member' => \$member])\n"
            . "@if (\$member)\n@includeIsolated('narrow-truthy', ['member' => \$member])\n@endif\n"
            . "@if (\$member !== null)\n@includeIsolated('narrow-notnull', ['member' => \$member])\n@endif\n",
        // Once the template has read `$member`, Psalm leaves no trace of an `isset()` around the include in
        // its context, so the file's `isset()` is what declines the name.
        'narrow-isset-top' => "{{ \$member?->slug }}\n@isset(\$member)\n@includeIsolated('narrow-isset', ['member' => \$member])\n@endisset\n",
        'narrow-plain' => "<p>{{ \$member?->slug }}</p>\n",
        'narrow-truthy' => "<p>{{ \$member?->slug }}</p>\n",
        'narrow-notnull' => "<p>{{ \$member?->slug }}</p>\n",
        'narrow-isset' => "<p>{{ \$member?->slug }}</p>\n",
        // The parent's own call site passes a typed `member` too, which must not be taken for the local.
        'local-loop' => "@foreach (json_decode('[]') as \$member)\n@includeIsolated('local-loop-leaf', ['member' => \$member])\n@endforeach\n",
        'local-loop-leaf' => "<p>{{ \$member->slug }}</p>\n",
        'local-php' => "@php(\$member = json_decode('{}'))\n@includeIsolated('local-php-leaf', ['member' => \$member])\n",
        'local-php-leaf' => "<p>{{ \$member->slug }}</p>\n",
        'reads-top' => "<?php extract([]); ?>\n@includeIsolated('reads-leaf', ['member' => \$member])\n",
        'reads-leaf' => "<p>{{ \$member->slug }}</p>\n",
        // The parent's call sites disagree (`int`, `string`), and the child has a typed one of its own.
        'split-top' => "@includeIsolated('split-leaf', ['member' => \$member])\n",
        'split-leaf' => "<p>{{ \$member }}</p>\n",
        // A write that keeps the type id `mixed`: `??=` is no plain assignment, so `$x` is no local.
        'wr-coalesce' => "@php\n\$x ??= json_decode('{}');\n@endphp\n@includeIsolated('wr-coalesce-leaf', ['x' => \$x])\n",
        'wr-coalesce-leaf' => "<p>{{ \$x?->slug }}</p>\n",
        // A reference alias: the condition names `$alias`, not `$x`.
        'wr-alias-clause' => "@php\n\$alias =& \$x;\n@endphp\n@if (\$alias !== null)\n@includeIsolated('wr-alias-clause-leaf', ['x' => \$x])\n@endif\n",
        'wr-alias-clause-leaf' => "<p>{{ \$x?->slug }}</p>\n",
        'wr-alias-write' => "@php\n\$alias =& \$x;\n\$alias = json_decode('{}');\n@endphp\n@includeIsolated('wr-alias-write-leaf', ['x' => \$x])\n",
        'wr-alias-write-leaf' => "<p>{{ \$x?->slug }}</p>\n",
        // `unset()` and a by-reference array item write the variable without a plain assignment: both keep
        // the type id `mixed`, and neither is a local.
        'wr-touch' => "@php\nunset(\$u);\n\$refs = [&\$r];\n@endphp\n"
            . "@includeIsolated('wr-touch-unset', ['u' => \$u])\n@includeIsolated('wr-touch-array', ['r' => \$r])\n",
        'wr-touch-unset' => "<p>{{ \$u?->slug }}</p>\n",
        'wr-touch-array' => "<p>{{ \$r?->slug }}</p>\n",
    ];

    /**
     * Under `resources/pkg-views`, a `loadViewsFrom()` hint root outside vendor/ that the fixture's
     * bootstrap registers as `pkg`. `spelled` and `padded` are rendered under a spelling Laravel
     * normalizes (`pkg::spelled::tail`, `' pkg::padded '`).
     */
    private const PKG_TEMPLATES = ['widget', 'spelled', 'padded'];

    private const PKG_WIDGET = "<p>{{ \$title }}</p>\n";

    /** Not a control file the CLI wrote: it lacks the marker key. */
    private const DECOY = '{"name":"acme/project","require":{"php":"^8.2"}}';

    private static ?string $fixtureCopy = null;

    /** @var array<string, array{output: string, control: string, templates: array<string, string>}> */
    private static array $annotateRuns = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$fixtureCopy !== null) {
            self::removeDirectory(self::$fixtureCopy);
        }

        self::$fixtureCopy = null;
        self::$annotateRuns = [];
    }

    /**
     * @param null|'decoy'|'annotate'|'dry-run' $mode `null` is an ordinary run with no control file;
     *                                                `decoy` points the variable at a file that is
     *                                                not this CLI's
     *
     * @return array{output: string, control: string, templates: array<string, string>} the JSON report
     *     (info issues included), the control file's contents afterwards, and every template as the
     *     run left it, keyed by name (`pkg:` prefixed under pkg-views)
     */
    private function analyse(?string $mode, string $config = 'psalm.xml'): array
    {
        return self::$annotateRuns[($mode ?? 'ordinary') . '|' . $config] ??= $this->analyseUncached($mode, $config);
    }

    /**
     * @return array<string, mixed> the annotate pass's result, as the writer reported it back
     *                              through the control file
     */
    private function annotateResult(string $mode = 'annotate'): array
    {
        $decoded = \json_decode($this->analyse($mode)['control'], true);
        $this->assertIsArray($decoded, 'the annotate pass never reported back');

        return $decoded;
    }

    /** @param array<string, string> $templates a run's template snapshot */
    private function assertTemplatesPristine(array $templates): void
    {
        foreach (self::TEMPLATES as $name => $contents) {
            $this->assertSame($contents, $templates[$name] ?? null, "{$name} must be left alone");
        }

        foreach (self::PKG_TEMPLATES as $name) {
            $this->assertSame(self::PKG_WIDGET, $templates["pkg:{$name}"] ?? null, "pkg:{$name} must be left alone");
        }
    }

    /**
     * Puts every template back as the fixture wrote it. A case that runs the writer again by hand
     * (a second annotate run over its own output) calls this, so the cases that read a pristine
     * copy still can.
     */
    private function restorePristineTemplates(): void
    {
        $copy = self::fixture();

        foreach (self::TEMPLATES as $name => $contents) {
            \file_put_contents("{$copy}/resources/views/{$name}.blade.php", $contents);
        }

        foreach (self::PKG_TEMPLATES as $name) {
            \file_put_contents("{$copy}/resources/pkg-views/{$name}.blade.php", self::PKG_WIDGET);
        }
    }

    /**
     * One uncached subprocess against the copy's current state: the memo above cannot serve a case
     * that mutates the templates first.
     *
     * @param null|'decoy'|'annotate'|'dry-run' $mode
     *
     * @return array{output: string, control: string, templates: array<string, string>}
     */
    private function analyseUncached(?string $mode, string $config = 'psalm.xml'): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found, run composer install.');

        $fixture = self::fixture();
        $controlFile = null;

        if ($mode !== null) {
            $controlFile = \tempnam(\sys_get_temp_dir(), 'psalm-laravel-annotate-e2e');
            $this->assertIsString($controlFile);
            \file_put_contents($controlFile, $mode === 'decoy'
                ? self::DECOY
                : (string) \json_encode([AnnotateRequest::MARKER => 1, 'dryRun' => $mode === 'dry-run']));
        }

        try {
            $process = new Process(
                [\PHP_BINARY, $psalmBinary, '-c', $config, '--no-cache', '--threads=2', '--no-progress', '--output-format=json', '--show-info=true'],
                $fixture,
                $controlFile === null ? null : [AnnotateRequest::ENV_VAR => $controlFile],
            );
            $process->setTimeout(300);
            // Not mustRun(): the fixture reports issues of its own at errorLevel 1.
            $process->run();

            return [
                'output' => $process->getOutput(),
                'control' => $controlFile === null ? '' : (string) \file_get_contents($controlFile),
                'templates' => self::templates(),
            ];
        } finally {
            if ($controlFile !== null) {
                @\unlink($controlFile);
            }
        }
    }

    /** @return array<string, string> */
    private static function templates(): array
    {
        $templates = [];

        foreach (\glob(self::fixture() . '/resources/views/*.blade.php') ?: [] as $path) {
            $templates[\basename($path, '.blade.php')] = (string) \file_get_contents($path);
        }

        foreach (\glob(self::fixture() . '/resources/pkg-views/*.blade.php') ?: [] as $path) {
            $templates['pkg:' . \basename($path, '.blade.php')] = (string) \file_get_contents($path);
        }

        return $templates;
    }

    /** The private copy, with the templates written into it. */
    private static function fixture(): string
    {
        if (self::$fixtureCopy !== null) {
            return self::$fixtureCopy;
        }

        // realpath(): Psalm reports canonical paths, and macOS's temp dir is a symlink.
        $copy = \realpath(\sys_get_temp_dir()) . '/psalm-annotate-e2e-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        self::copyDirectory(__DIR__ . '/Fixtures/Annotate', $copy);
        self::$fixtureCopy = $copy;

        foreach (self::TEMPLATES as $name => $contents) {
            \file_put_contents("{$copy}/resources/views/{$name}.blade.php", $contents);
        }

        foreach (self::PKG_TEMPLATES as $name) {
            \file_put_contents("{$copy}/resources/pkg-views/{$name}.blade.php", self::PKG_WIDGET);
        }

        return $copy;
    }
}
