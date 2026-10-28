<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\VendorDirectory;

/**
 * The vendor boundary is the Composer install root, never the literal substring `vendor`. Both
 * discriminating cases below are ones a `str_contains($path, '/vendor/')` test gets wrong, in
 * opposite directions: a renamed `config.vendor-dir` makes it fail OPEN (nothing is recognised as
 * vendor code), and a project whose own path carries a `vendor` segment makes it fail CLOSED
 * (project code is mistaken for vendor code, silently).
 */
#[CoversClass(VendorDirectory::class)]
final class VendorDirectoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $root = \realpath(\sys_get_temp_dir()) . '/psalm-vendor-dir-' . \bin2hex(\random_bytes(8));
        \mkdir($root, 0o777, true);
        $this->root = $root;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        foreach (\array_diff(\scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            \is_dir($child) ? $this->removeTree($child) : \unlink($child);
        }

        \rmdir($path);
    }

    private function touchFile(string $relative): string
    {
        $path = $this->root . '/' . $relative;
        \mkdir(\dirname($path), 0o777, true);
        \file_put_contents($path, "<?php\n");

        return $path;
    }

    #[Test]
    public function a_file_under_a_renamed_vendor_directory_is_recognised(): void
    {
        $helper = $this->touchFile('libs/acme/package/helpers.php');

        $this->assertTrue(VendorDirectory::contains($helper, $this->root . '/libs'));
    }

    #[Test]
    public function a_project_path_carrying_a_vendor_segment_is_not_vendor_code(): void
    {
        $helper = $this->touchFile('vendor/shop/packages/Demo/helpers.php');
        $this->touchFile('libs/laravel/framework/src/marker.php');

        $this->assertFalse(VendorDirectory::contains($helper, $this->root . '/libs'));
    }

    #[Test]
    public function a_sibling_directory_sharing_the_vendor_prefix_is_not_inside_it(): void
    {
        $helper = $this->touchFile('vendor-extra/helpers.php');
        $this->touchFile('vendor/placeholder.php');

        $this->assertFalse(VendorDirectory::contains($helper, $this->root . '/vendor'));
    }

    #[Test]
    public function an_unresolvable_path_is_not_inside_the_vendor_directory(): void
    {
        $this->touchFile('vendor/placeholder.php');

        $this->assertFalse(VendorDirectory::contains($this->root . '/vendor/gone.php', $this->root . '/vendor'));
    }

    /**
     * Derived from where Composer installed laravel/framework, so it survives a `vendor-dir`
     * rename. This checkout has one, which is what makes the assertion meaningful rather than a
     * null check.
     */
    #[Test]
    public function the_install_root_is_derived_from_composer_metadata(): void
    {
        $path = VendorDirectory::path();

        $this->assertIsString($path);
        $this->assertSame(\realpath(\dirname(__DIR__, 3) . '/vendor'), \realpath($path));
        $this->assertTrue(VendorDirectory::contains($path . '/laravel/framework/src/Illuminate/Support/helpers.php', $path));
    }
}
