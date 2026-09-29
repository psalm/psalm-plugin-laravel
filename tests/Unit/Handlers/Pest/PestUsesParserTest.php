<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Pest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Pest\PestUsesParser;

#[CoversClass(PestUsesParser::class)]
final class PestUsesParserTest extends TestCase
{
    private string $root;

    private string $pestFile;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/pest-uses-parser-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/tests/Feature/Api', 0o777, true);
        \mkdir($this->root . '/tests/Unit', 0o777, true);
        \touch($this->root . '/tests/Feature/Api/UserTest.php');
        $this->root = (string) \realpath($this->root);
        $this->pestFile = $this->root . '/tests/Pest.php';
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->root);
    }

    #[Test]
    public function uses_in_relative_directory(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase', 'Illuminate\Foundation\Testing\RefreshDatabase'], 'targets' => [$this->root . '/tests/Feature']]],
            $this->parsePest(<<<'PHP'
                use Tests\TestCase;
                uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature');
                PHP),
        );
    }

    #[Test]
    public function pest_extend_chain_with_several_targets_and_ignored_calls(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase', 'Tests\Concerns\Seeds'], 'targets' => [$this->root . '/tests/Feature', $this->root . '/tests/Unit']]],
            $this->parsePest(<<<'PHP'
                namespace Tests;
                pest()->extend(TestCase::class)->use(Concerns\Seeds::class)->group('db')->in('Feature', 'Unit');
                PHP),
        );
    }

    #[Test]
    public function dir_magic_constant_and_concatenation(): void
    {
        $this->assertSame(
            [
                ['classes' => ['Tests\TestCase'], 'targets' => [$this->root . '/tests']],
                ['classes' => ['Tests\ApiCase'], 'targets' => [$this->root . '/tests/Feature/Api']],
            ],
            $this->parsePest(<<<'PHP'
                uses(Tests\TestCase::class)->in(__DIR__);
                pest()->extends('\Tests\ApiCase')->in(__DIR__ . '/Feature/Api');
                PHP),
        );
    }

    #[Test]
    public function glob_targets_are_expanded_and_missing_ones_dropped(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase'], 'targets' => [$this->root . '/tests/Feature/Api/UserTest.php']]],
            $this->parsePest("uses(Tests\\TestCase::class)->in('Feature/*/*Test.php', 'Missing');"),
        );
    }

    #[Test]
    public function pest_without_in_covers_the_pest_php_directory_but_uses_targets_only_itself(): void
    {
        $this->assertSame(
            [
                ['classes' => ['Tests\TestCase'], 'targets' => [$this->root . '/tests']],
                ['classes' => ['Tests\Other'], 'targets' => [$this->pestFile]],
            ],
            $this->parsePest("pest()->extend(Tests\\TestCase::class);\nuses(Tests\\Other::class);"),
        );
    }

    #[Test]
    public function in_file_calls_target_the_file_itself(): void
    {
        $testFile = $this->root . '/tests/Feature/Api/UserTest.php';

        $this->assertSame(
            [
                ['classes' => ['Tests\ApiCase'], 'targets' => [$testFile]],
                ['classes' => ['Tests\Other'], 'targets' => [$testFile]],
            ],
            PestUsesParser::parse($testFile, "<?php\nuses(Tests\\ApiCase::class);\npest()->extend(Tests\\Other::class);\ntest('x', fn () => 1);"),
        );
    }

    #[Test]
    public function comments_between_name_and_arguments_still_parse(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase'], 'targets' => [$this->pestFile]]],
            $this->parsePest('uses /* comment */ (Tests\TestCase::class);'),
        );
    }

    #[Test]
    public function returns_inside_function_bodies_do_not_decline(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase'], 'targets' => [$this->root . '/tests']]],
            $this->parsePest("function helper(): int { return 1; }\npest()->extend(Tests\\TestCase::class);"),
        );
    }

    #[Test]
    public function files_without_uses_or_pest_calls_have_no_entries(): void
    {
        $this->assertSame([], $this->parsePest("expect()->extend('toBeOne', fn () => \$this->toBe(1));"));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableSources(): iterable
    {
        yield 'variable class' => ['uses($case)->in("Feature");'];
        yield 'variable target' => ['uses(Tests\TestCase::class)->in($dir);'];
        yield 'unpacked classes' => ['uses(...$cases)->in("Feature");'];
        yield 'named argument' => ['pest()->extend(classAndTraits: Tests\TestCase::class);'];
        yield 'static class' => ['uses(static::class);'];
        yield 'dynamic method' => ['pest()->{$method}(Tests\TestCase::class);'];
        yield 'dynamic target call' => ['uses(Tests\TestCase::class)->in(dirname(__DIR__));'];
        yield 'conditional' => ['if (true) { uses(Tests\TestCase::class)->in("Feature"); }'];
        yield 'assigned' => ['$config = pest()->extend(Tests\TestCase::class);'];
        yield 'nested in a closure' => ['beforeEach(function () { uses(Tests\TestCase::class); });'];
        yield 'parse error' => ['uses(Tests\TestCase::class)->in('];
        // External review findings: every shape Pest honors but this parser cannot model declines.
        yield 'aliased function import' => ["use function uses as bindCase;\nbindCase(Tests\\TestCase::class);"];
        yield 'function name as string' => ["call_user_func('uses', Tests\\TestCase::class);"];
        yield 'early return before config' => ["if (getenv('PEST_USE_DEFAULT')) {\n    return;\n}\npest()->extend(Tests\\TestCase::class);"];
        yield 'exit before config' => ["getenv('CI') or exit;\npest()->extend(Tests\\TestCase::class);"];
        yield 'include of more config' => ["require __DIR__ . '/bindings.php';"];
        yield 'include inside a function' => ["function boot(): void { include_once 'x.php'; }"];
        yield 'first-class callable method' => ['pest()->extend(...)->__invoke(Tests\TestCase::class);'];
        yield 'first-class callable root' => ['uses(...);'];
    }

    #[Test]
    #[DataProvider('unreadableSources')]
    public function unreadable_sources_are_unknown(string $source): void
    {
        $this->assertNull($this->parsePest($source));
    }

    /** @return list<array{classes: list<string>, targets: list<string>}>|null */
    private function parsePest(string $source): ?array
    {
        return PestUsesParser::parse($this->pestFile, "<?php\n" . $source);
    }
}
