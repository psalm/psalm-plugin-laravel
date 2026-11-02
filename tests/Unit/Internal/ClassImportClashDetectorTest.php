<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Aliases;
use Psalm\LaravelPlugin\Internal\ClassImportClashDetector;
use Psalm\Storage\ClassLikeStorage;

#[CoversClass(ClassImportClashDetector::class)]
final class ClassImportClashDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?array<lowercase-string, string>, bool}>
     */
    public static function cases(): iterable
    {
        yield 'import of another class under the same short name' => ['App\\Cache', ['cache' => 'Illuminate\\Support\\Facades\\Cache'], true];
        yield 'import of the class itself, any case (PHP accepts it)' => ['App\\Cache', ['cache' => 'app\\CACHE'], false];
        yield 'unrelated import' => ['App\\Cache', ['facade' => 'Illuminate\\Support\\Facades\\Facade'], false];
        yield 'no aliases' => ['App\\Cache', null, false];
    }

    /**
     * @param ?array<lowercase-string, string> $uses
     */
    #[Test]
    #[DataProvider('cases')]
    public function it_detects_a_class_name_taken_by_a_use_import(string $fqcn, ?array $uses, bool $expected): void
    {
        $storage = new ClassLikeStorage($fqcn);
        $storage->aliases = $uses === null ? null : new Aliases('App', $uses);

        $this->assertSame($expected, ClassImportClashDetector::clashes($storage));
    }
}
