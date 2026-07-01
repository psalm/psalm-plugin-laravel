<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\InternalErrorClassifier;

#[CoversClass(InternalErrorClassifier::class)]
final class InternalErrorClassifierTest extends TestCase
{
    /**
     * Tests classify by stable category nouns only — the rest of the hint copy
     * is user-facing prose and may be reworded without breaking these tests.
     */
    #[Test]
    #[DataProvider('classificationProvider')]
    public function it_classifies_files_by_path(string $file, string $expectedCategory): void
    {
        $hint = InternalErrorClassifier::hintForFile($file);

        $this->assertNotNull($hint, "expected a hint for {$file}");
        $this->assertStringContainsString($expectedCategory, $hint);
    }

    /** @return iterable<string, array{string, string}> */
    public static function classificationProvider(): iterable
    {
        yield 'Laravel framework' => [
            '/home/user/project/vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
            'Laravel framework',
        ];

        yield 'illuminate split package' => [
            '/home/user/project/vendor/illuminate/container/Container.php',
            'Laravel framework',
        ];

        yield 'Orchestra Testbench' => [
            '/home/user/project/vendor/orchestra/testbench-core/src/Foundation/Application.php',
            'Orchestra Testbench',
        ];

        yield 'plugin development checkout' => [
            '/home/dev/code/psalm-plugin-laravel/src/Bootstrap/ApplicationProvider.php',
            'Laravel plugin',
        ];

        yield 'plugin installed via composer wins over generic vendor' => [
            '/home/user/app/vendor/psalm/plugin-laravel/src/Bootstrap/ApplicationProvider.php',
            'Laravel plugin',
        ];

        yield 'third-party vendor' => [
            '/home/user/app/vendor/symfony/console/Application.php',
            'third-party package',
        ];

        yield 'user bootstrap/app.php' => [
            '/home/user/app/bootstrap/app.php',
            'application code',
        ];

        yield 'user app/Providers' => [
            '/home/user/app/app/Providers/AppServiceProvider.php',
            'application code',
        ];

        yield 'windows path is normalised' => [
            'C:\\Users\\dev\\app\\vendor\\laravel\\framework\\Application.php',
            'Laravel framework',
        ];
    }

    #[Test]
    public function it_skips_internal_error_machinery_frames(): void
    {
        $this->assertNull(InternalErrorClassifier::hintForFile('/x/src/Internal/InternalErrorReporter.php'));
        $this->assertNull(InternalErrorClassifier::hintForFile('/x/src/Internal/InternalErrorClassifier.php'));
    }

    #[Test]
    public function it_returns_null_for_empty_path(): void
    {
        // hint() filters empty paths before calling hintForFile, but the public
        // surface should still treat an empty path as unclassifiable rather than
        // as user code with empty parentheses
        $this->assertNull(InternalErrorClassifier::hintForFile(''));
    }

    #[Test]
    public function it_returns_a_hint_for_a_real_throwable(): void
    {
        // Smoke test: a real throwable always has at least one classifiable frame
        // (this test file itself is enough). Verifies frameFiles() is wired up
        // and the hint() loop reaches a classifiable frame.
        $hint = InternalErrorClassifier::hint(new \RuntimeException('boom'));

        $this->assertNotNull($hint);
    }
}
