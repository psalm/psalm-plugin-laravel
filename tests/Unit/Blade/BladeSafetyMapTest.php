<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Blade;

use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeSafetyMap;
use Psalm\LaravelPlugin\Blade\BladeUncertaintyReason;
use Psalm\LaravelPlugin\Blade\BladeViewSafetyKind;

final class BladeSafetyMapTest extends TestCase
{
    /** @var list<string> */
    private array $rootsToCleanUp = [];

    /**
     * Initialised in {@see setUp()}, which PHPUnit invokes before each test
     * method. Declared `string` rather than `?string` because every test
     * method dereferences it; a null sentinel would only delay the obvious
     * failure (and trip Psalm's PossiblyNullArgument on every read).
     */
    private string $root = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->root = $this->makeTempRoot();

        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'emails', 0777, true);
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'posts', 0777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->rootsToCleanUp as $root) {
            $this->removeTree($root);
        }

        $this->rootsToCleanUp = [];
    }

    public function test_dotted_view_names_are_derived_from_relative_path(): void
    {
        $this->writeBlade($this->root, 'emails/welcome.blade.php', '{!! $banner !!}');

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['banner'], $map->unsafeKeysFor('emails.welcome'));
        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $map->safetyFor('emails.welcome')?->kind());
    }

    public function test_nested_subdirectory_produces_multi_segment_view_name(): void
    {
        \mkdir($this->root . '/partials/forms', 0777, true);
        $this->writeBlade($this->root, 'partials/forms/input.blade.php', '{!! $html !!}');

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['html'], $map->unsafeKeysFor('partials.forms.input'));
    }

    public function test_safe_templates_are_recorded_as_safe(): void
    {
        // Earlier versions only stored unsafe templates, leaving SAFE views
        // indistinguishable from "never scanned". The handler layer needs the
        // distinction to decide between "no sink" and "apply UNKNOWN fallback".
        $this->writeBlade($this->root, 'posts/show.blade.php', '<h1>{{ $post->title }}</h1>');

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isKnownSafe('posts.show'));
        $this->assertFalse($map->isUnknown('posts.show'));
        $this->assertSame([], $map->unsafeKeysFor('posts.show'));
        $this->assertSame(['posts.show'], $map->knownViews());
        $this->assertSame(BladeViewSafetyKind::Safe, $map->safetyFor('posts.show')?->kind());
    }

    public function test_layout_template_is_recorded_as_unknown(): void
    {
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'layouts', 0777, true);
        $this->writeBlade(
            $this->root,
            'layouts/app.blade.php',
            "<html><body>@yield('content')</body></html>",
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isUnknown('layouts.app'));

        $safety = $map->safetyFor('layouts.app');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
        $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $safety->uncertainties());
    }

    public function test_multiple_unsafe_keys_are_collected(): void
    {
        $this->writeBlade($this->root, 'emails/digest.blade.php', <<<'BLADE'
            {!! $summaryHtml !!}
            @php echo $rawFooter; @endphp
            BLADE);

        $map = BladeSafetyMap::build([$this->root]);

        $unsafe = $map->unsafeKeysFor('emails.digest');

        \sort($unsafe);

        $this->assertSame(['rawFooter', 'summaryHtml'], $unsafe);
    }

    public function test_first_matching_path_wins_for_unsafe_view(): void
    {
        // Laravel's FileViewFinder iterates paths in order and returns the
        // first match. BladeSafetyMap mirrors that: the first occurrence of a
        // given view name wins.
        $altRoot = $this->makeTempRoot();
        \mkdir($altRoot . \DIRECTORY_SEPARATOR . 'emails', 0777, true);

        $this->writeBlade($this->root, 'emails/welcome.blade.php', '{!! $primary !!}');
        $this->writeBlade($altRoot, 'emails/welcome.blade.php', '{!! $override !!}');

        $map = BladeSafetyMap::build([$this->root, $altRoot]);

        $this->assertSame(['primary'], $map->unsafeKeysFor('emails.welcome'));
    }

    public function test_first_matching_path_wins_when_first_is_safe(): void
    {
        // Regression for the earlier PoC: when the first root had a SAFE view
        // and a later root had an UNSAFE view, the map skipped the SAFE one
        // and stored the UNSAFE shadow — which Laravel would never render.
        // The map must record the first match regardless of safety kind.
        $altRoot = $this->makeTempRoot();
        \mkdir($altRoot . \DIRECTORY_SEPARATOR . 'emails', 0777, true);

        $this->writeBlade($this->root, 'emails/welcome.blade.php', '<h1>{{ $title }}</h1>');
        $this->writeBlade($altRoot, 'emails/welcome.blade.php', '{!! $injected !!}');

        $map = BladeSafetyMap::build([$this->root, $altRoot]);

        $this->assertTrue($map->isKnownSafe('emails.welcome'));
        $this->assertSame([], $map->unsafeKeysFor('emails.welcome'));
    }

    public function test_view_in_second_path_is_picked_up_when_absent_from_first(): void
    {
        $altRoot = $this->makeTempRoot();
        \mkdir($altRoot . \DIRECTORY_SEPARATOR . 'emails', 0777, true);

        // Only in the second path — must still be discovered.
        $this->writeBlade($altRoot, 'emails/overflow.blade.php', '{!! $content !!}');

        $map = BladeSafetyMap::build([$this->root, $altRoot]);

        $this->assertSame(['content'], $map->unsafeKeysFor('emails.overflow'));
    }

    public function test_non_blade_siblings_are_ignored(): void
    {
        // Editor swap files and legacy .php views should not be parsed as
        // blade templates. str_ends_with ensures `foo.blade.php.bak` is out.
        $this->writeBlade($this->root, 'emails/legit.blade.php', '{!! $html !!}');
        \file_put_contents($this->root . '/emails/legacy.php', '<?= $neverScanned ?>');
        \file_put_contents($this->root . '/emails/legit.blade.php.bak', '{!! $leaked !!}');
        \file_put_contents($this->root . '/emails/legit.blade.php~', '{!! $alsoLeaked !!}');

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['emails.legit'], $map->knownViews());
        $this->assertSame(['html'], $map->unsafeKeysFor('emails.legit'));
    }

    public function test_missing_view_directory_is_silently_skipped(): void
    {
        // Build tolerates a configured-but-not-yet-created view root. Matches
        // the permissive behaviour of MissingViewHandler::viewFileExists().
        $map = BladeSafetyMap::build(['/nonexistent/path/to/views']);

        $this->assertSame([], $map->knownViews());
    }

    public function test_unknown_view_name_returns_null_from_safety_for(): void
    {
        $map = BladeSafetyMap::build([$this->root]);

        $this->assertNotInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $map->safetyFor('never.scanned'));
        $this->assertFalse($map->isKnownSafe('never.scanned'));
        $this->assertFalse($map->isUnknown('never.scanned'));
        $this->assertSame([], $map->unsafeKeysFor('never.scanned'));
    }

    public function test_unreadable_blade_file_is_recorded_as_unknown(): void
    {
        // Verifies the FILE_UNREADABLE branch in BladeSafetyMap::build().
        // chmod 0000 is ineffective on Windows and as root, so we skip in
        // those environments rather than fake the test.
        if (\PHP_OS_FAMILY === 'Windows' || (\function_exists('posix_geteuid') && \posix_geteuid() === 0)) {
            $this->markTestSkipped('chmod 0000 not effective on Windows or as root');
        }

        $path = $this->root . \DIRECTORY_SEPARATOR . 'emails' . \DIRECTORY_SEPARATOR . 'locked.blade.php';
        \file_put_contents($path, '{!! $banner !!}');
        \chmod($path, 0000);

        try {
            $map = BladeSafetyMap::build([$this->root]);

            $this->assertTrue($map->isUnknown('emails.locked'));
            $safety = $map->safetyFor('emails.locked');
            $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
            $this->assertContains(
                BladeUncertaintyReason::FileUnreadable,
                $safety->uncertainties(),
            );
        } finally {
            // Restore so the recursive teardown can unlink the file.
            \chmod($path, 0644);
        }
    }

    private function writeBlade(string $root, string $relativePath, string $contents): void
    {
        \file_put_contents($root . \DIRECTORY_SEPARATOR . $relativePath, $contents);
    }

    private function makeTempRoot(): string
    {
        $root = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'blade-safety-map-' . \uniqid('', true);

        \mkdir($root, 0777, true);

        $this->rootsToCleanUp[] = $root;

        return $root;
    }

    /**
     * Recursively remove a directory tree. Intentionally tolerant of
     * already-missing paths so teardown stays idempotent.
     */
    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                \rmdir($file->getPathname());
            } else {
                \unlink($file->getPathname());
            }
        }

        \rmdir($path);
    }
}
