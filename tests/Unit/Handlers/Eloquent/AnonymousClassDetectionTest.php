<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;

/**
 * Tests that Psalm's synthetic FQCNs for anonymous classes (e.g. `new class extends Model {}`)
 * are recognised and skipped, so the plugin doesn't emit a misleading "class could not be loaded
 * by autoloader" warning for names that are never autoloadable.
 *
 * @see \Psalm\Internal\Analyzer\ClassAnalyzer::getAnonymousClassName()
 */
#[CoversClass(ModelRegistrationHandler::class)]
final class AnonymousClassDetectionTest extends TestCase
{
    #[Test]
    #[DataProvider('anonymousNames')]
    public function it_detects_synthetic_anonymous_class_names(string $fqcn, string $filePath): void
    {
        $this->assertTrue($this->call($fqcn, $filePath));
    }

    #[Test]
    #[DataProvider('regularNames')]
    public function it_ignores_regular_class_names(string $fqcn, string $filePath): void
    {
        $this->assertFalse($this->call($fqcn, $filePath));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function anonymousNames(): iterable
    {
        // Real-world example from Algolia\ScoutExtended (motivating case for this fix).
        yield 'algolia scout aggregator' => [
            'Algolia\\ScoutExtended\\Searchable\\_Users_alies_code_IxDF_IxDF_web_vendor_algolia_scout_extended_src_Searchable_Aggregator_php_279_6842',
            '/Users/alies/code/IxDF/IxDF-web/vendor/algolia/scout-extended/src/Searchable/Aggregator.php',
        ];

        yield 'namespaced unix path' => [
            'App\\Models\\_home_app_src_Foo_php_10_42',
            '/home/app/src/Foo.php',
        ];

        yield 'no namespace' => [
            '_home_app_src_Foo_php_5_20',
            '/home/app/src/Foo.php',
        ];

        // Windows paths get sanitised to start with a drive letter (e.g. `C`).
        yield 'windows path' => [
            'App\\C__path_to_Foo_php_3_12',
            'C:\\path\\to\\Foo.php',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function regularNames(): iterable
    {
        yield 'plain model class' => [
            'App\\Models\\User',
            '/home/app/src/Models/User.php',
        ];

        yield 'short name does not match sanitised file path' => [
            'App\\Models\\_home_other_file_php_1_1',
            '/home/app/src/Models/User.php',
        ];

        yield 'missing line/pos suffix' => [
            'App\\_home_app_src_Foo_php',
            '/home/app/src/Foo.php',
        ];

        yield 'single numeric suffix (not line_pos)' => [
            'App\\_home_app_src_Foo_php_10',
            '/home/app/src/Foo.php',
        ];

        yield 'empty file path' => [
            'App\\Models\\_10_20',
            '',
        ];

        yield 'class with trailing digits but no path prefix' => [
            'App\\Models\\Snapshot_1_2',
            '/home/app/src/Models/Snapshot.php',
        ];
    }

    private function call(string $fqcn, string $filePath): bool
    {
        $method = new \ReflectionMethod(ModelRegistrationHandler::class, 'isSyntheticAnonymousClassName');

        return (bool) $method->invoke(null, $fqcn, $filePath);
    }
}
