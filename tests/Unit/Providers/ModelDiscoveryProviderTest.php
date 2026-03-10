<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ModelDiscoveryProvider;

#[CoversClass(ModelDiscoveryProvider::class)]
final class ModelDiscoveryProviderTest extends TestCase
{
    private static ?\ReflectionMethod $extractClassName = null;

    private ?string $tempFile = null;

    public static function setUpBeforeClass(): void
    {
        self::$extractClassName = new \ReflectionMethod(ModelDiscoveryProvider::class, 'extractClassName');
    }

    protected function tearDown(): void
    {
        if ($this->tempFile !== null && \file_exists($this->tempFile)) {
            \unlink($this->tempFile);
        }
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function extract_class_name_cases(): iterable
    {
        yield 'simple class' => [
            '<?php namespace App\Models; class User {}',
            'App\Models\User',
        ];

        yield 'final class' => [
            '<?php namespace App\Models; final class User {}',
            'App\Models\User',
        ];

        yield 'abstract class' => [
            '<?php namespace App\Models; abstract class BaseModel {}',
            'App\Models\BaseModel',
        ];

        yield 'readonly class' => [
            '<?php namespace App\Models; readonly class User {}',
            'App\Models\User',
        ];

        yield 'final readonly class' => [
            '<?php namespace App\Models; final readonly class User {}',
            'App\Models\User',
        ];

        yield 'abstract readonly class' => [
            '<?php namespace App\Models; abstract readonly class BaseModel {}',
            'App\Models\BaseModel',
        ];

        yield 'no namespace' => [
            '<?php class LegacyModel {}',
            'LegacyModel',
        ];

        yield 'class inside block comment is ignored' => [
            <<<'PHP'
            <?php
            namespace App\Models;
            /*
            class OldModelName
            */
            class ActualModel {}
            PHP,
            'App\Models\ActualModel',
        ];

        yield 'class in single-line comment is ignored' => [
            <<<'PHP'
            <?php
            namespace App\Models;
            // class FakeModel
            class RealModel {}
            PHP,
            'App\Models\RealModel',
        ];

        yield 'class in string is ignored' => [
            <<<'PHP'
            <?php
            namespace App\Models;
            $x = 'class NotAClass {}';
            class RealModel {}
            PHP,
            'App\Models\RealModel',
        ];

        yield 'trait returns null' => [
            '<?php namespace App\Traits; trait HasSlug {}',
            null,
        ];

        yield 'interface returns null' => [
            '<?php namespace App\Contracts; interface Searchable {}',
            null,
        ];

        yield 'enum returns null' => [
            '<?php namespace App\Enums; enum Status: string { case Active = "active"; }',
            null,
        ];

        yield 'empty file returns null' => [
            '<?php',
            null,
        ];

        yield 'class with extends' => [
            '<?php namespace App\Models; class Post extends Model {}',
            'App\Models\Post',
        ];

        yield 'multiline namespace and class' => [
            <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            class Post extends Model
            {
            }
            PHP,
            'App\Models\Post',
        ];

        yield '::class constant before real class' => [
            <<<'PHP'
            <?php
            namespace App\Models;
            $x = SomeOther::class;
            class User {}
            PHP,
            'App\Models\User',
        ];

        yield 'anonymous class before real class' => [
            <<<'PHP'
            <?php
            namespace App\Models;
            $x = new class extends Model {};
            class User {}
            PHP,
            'App\Models\User',
        ];

        yield 'PHP attributes on class' => [
            <<<'PHP'
            <?php
            namespace App\Models;
            #[SomeAttribute]
            class User {}
            PHP,
            'App\Models\User',
        ];

        yield 'multiple classes returns first' => [
            '<?php namespace App\Models; class First {} class Second {}',
            'App\Models\First',
        ];

        yield 'bracketed namespace' => [
            '<?php namespace App\Models { class User {} }',
            'App\Models\User',
        ];
    }

    #[DataProvider('extract_class_name_cases')]
    public function test_extract_class_name(string $contents, ?string $expected): void
    {
        $this->tempFile = \tempnam(\sys_get_temp_dir(), 'psalm_test_');
        self::assertNotFalse($this->tempFile);
        \file_put_contents($this->tempFile, $contents);

        $result = self::$extractClassName->invoke(null, $this->tempFile);

        self::assertSame($expected, $result);
    }

    public function test_extract_class_name_returns_null_for_nonexistent_file(): void
    {
        $result = self::$extractClassName->invoke(null, '/nonexistent/path/file.php');

        self::assertNull($result);
    }
}
