<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Blade;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeSafetyMap;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

final class BladeSafetyMapTest extends TestCase
{
    /** @var list<string> */
    private array $rootsToCleanUp = [];

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->makeTempRoot();

        mkdir($this->root . DIRECTORY_SEPARATOR . 'emails', 0777, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'posts', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->rootsToCleanUp as $root) {
            self::removeTree($root);
        }

        $this->rootsToCleanUp = [];
    }

    public function test_dotted_view_names_are_derived_from_relative_path(): void
    {
        $this->writeBlade($this->root, 'emails/welcome.blade.php', '{!! $banner !!}');

        $map = BladeSafetyMap::build([$this->root]);

        self::assertSame(['banner'], $map->unsafeKeysFor('emails.welcome'));
        self::assertTrue($map->hasUnsafeKeys('emails.welcome'));
    }

    public function test_nested_subdirectory_produces_multi_segment_view_name(): void
    {
        mkdir($this->root . '/partials/forms', 0777, true);
        $this->writeBlade($this->root, 'partials/forms/input.blade.php', '{!! $html !!}');

        $map = BladeSafetyMap::build([$this->root]);

        self::assertSame(['html'], $map->unsafeKeysFor('partials.forms.input'));
    }

    public function test_safe_templates_are_not_recorded(): void
    {
        $this->writeBlade($this->root, 'posts/show.blade.php', '<h1>{{ $post->title }}</h1>');

        $map = BladeSafetyMap::build([$this->root]);

        self::assertFalse($map->hasUnsafeKeys('posts.show'));
        self::assertSame([], $map->unsafeKeysFor('posts.show'));
        self::assertSame([], $map->knownViews());
    }

    public function test_multiple_unsafe_keys_are_collected(): void
    {
        $this->writeBlade($this->root, 'emails/digest.blade.php', <<<'BLADE'
            {!! $summaryHtml !!}
            @php echo $rawFooter; @endphp
            BLADE);

        $map = BladeSafetyMap::build([$this->root]);

        $unsafe = $map->unsafeKeysFor('emails.digest');

        sort($unsafe);

        self::assertSame(['rawFooter', 'summaryHtml'], $unsafe);
    }

    public function test_first_matching_path_wins(): void
    {
        // Laravel's FileViewFinder iterates paths in order and returns the
        // first match. BladeSafetyMap mirrors that: the first occurrence of a
        // given view name wins.
        $altRoot = $this->makeTempRoot();
        mkdir($altRoot . DIRECTORY_SEPARATOR . 'emails', 0777, true);

        $this->writeBlade($this->root, 'emails/welcome.blade.php', '{!! $primary !!}');
        $this->writeBlade($altRoot, 'emails/welcome.blade.php', '{!! $override !!}');

        $map = BladeSafetyMap::build([$this->root, $altRoot]);

        self::assertSame(['primary'], $map->unsafeKeysFor('emails.welcome'));
    }

    public function test_view_in_second_path_is_picked_up_when_absent_from_first(): void
    {
        $altRoot = $this->makeTempRoot();
        mkdir($altRoot . DIRECTORY_SEPARATOR . 'emails', 0777, true);

        // Only in the second path — must still be discovered.
        $this->writeBlade($altRoot, 'emails/overflow.blade.php', '{!! $content !!}');

        $map = BladeSafetyMap::build([$this->root, $altRoot]);

        self::assertSame(['content'], $map->unsafeKeysFor('emails.overflow'));
    }

    public function test_non_blade_siblings_are_ignored(): void
    {
        // Editor swap files and legacy .php views should not be parsed as
        // blade templates. str_ends_with ensures `foo.blade.php.bak` is out.
        $this->writeBlade($this->root, 'emails/legit.blade.php', '{!! $html !!}');
        file_put_contents($this->root . '/emails/legacy.php', '<?= $neverScanned ?>');
        file_put_contents($this->root . '/emails/legit.blade.php.bak', '{!! $leaked !!}');
        file_put_contents($this->root . '/emails/legit.blade.php~', '{!! $alsoLeaked !!}');

        $map = BladeSafetyMap::build([$this->root]);

        self::assertSame(['emails.legit'], $map->knownViews());
        self::assertSame(['html'], $map->unsafeKeysFor('emails.legit'));
    }

    public function test_missing_view_directory_is_silently_skipped(): void
    {
        // Build tolerates a configured-but-not-yet-created view root. Matches
        // the permissive behaviour of MissingViewHandler::viewFileExists().
        $map = BladeSafetyMap::build(['/nonexistent/path/to/views']);

        self::assertSame([], $map->knownViews());
    }

    private function writeBlade(string $root, string $relativePath, string $contents): void
    {
        file_put_contents($root . DIRECTORY_SEPARATOR . $relativePath, $contents);
    }

    private function makeTempRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blade-safety-map-' . uniqid('', true);

        mkdir($root, 0777, true);

        $this->rootsToCleanUp[] = $root;

        return $root;
    }

    /**
     * Recursively remove a directory tree. Intentionally tolerant of
     * already-missing paths so teardown stays idempotent.
     */
    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}
