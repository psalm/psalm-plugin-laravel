<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Config\ConfigSource;
use Psalm\LaravelPlugin\Handlers\Config\ConfigSourceIndex;
use Psalm\LaravelPlugin\Handlers\Config\ConfigValueReflector;
use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;

#[CoversClass(ConfigValueReflector::class)]
final class ConfigValueReflectorTest extends TestCase
{
    #[Test]
    public function reflects_null_without_a_source_to_mixed(): void
    {
        // A null with nothing behind it is as likely an env var unset on this machine.
        $this->assertSame('mixed', ConfigValueReflector::reflect(null)->getId());
        $this->assertSame('mixed', ConfigValueReflector::reflect(null, $this->source('SOME_CONSTANT'))->getId());
    }

    #[Test]
    public function reflects_null_to_TNull_when_the_source_is_a_literal_null(): void
    {
        $this->assertTrue(ConfigValueReflector::reflect(null, $this->source('null'))->isNull());
    }

    #[Test]
    public function types_an_env_leaf_from_its_source_whatever_the_variable_reads(): void
    {
        $source = $this->source("env('FEATURE')");

        $this->assertSame('null|string', ConfigValueReflector::reflect(null, $source)->getId());
        $this->assertSame('null|string', ConfigValueReflector::reflect('secret', $source)->getId());
        // Env::get() maps FEATURE=true to a bool, which the source type leaves out but can produce.
        $this->assertSame('null|string', ConfigValueReflector::reflect(true, $source)->getId());
    }

    #[Test]
    public function a_set_variable_is_no_override_when_psalm_folds_what_the_source_produces_into_scalar(): void
    {
        // bool|float|int|null|string combines to null|scalar.
        $source = $this->source("env('TTL', env('FAST') ? 60 : 0.5)");

        $this->assertSame('float|int|string', ConfigValueReflector::reflect(null, $source)->getId());
        $this->assertSame('float|int|string', ConfigValueReflector::reflect('abc', $source)->getId());
        $this->assertSame('float|int|string', ConfigValueReflector::reflect(true, $source)->getId());
    }

    #[Test]
    public function a_value_the_env_source_cannot_produce_is_a_provider_override_typed_at_runtime(): void
    {
        $this->assertSame('string', ConfigValueReflector::reflect('remote', $this->source("(int) env('PORT')"))->getId());
        $this->assertSame('int', ConfigValueReflector::reflect(8080, $this->source("env('FEATURE')"))->getId());
        $this->assertSame('list{string}', ConfigValueReflector::reflect(['a'], $this->source("env('FEATURE')"))->getId());
        $this->assertSame('mixed', ConfigValueReflector::reflect(null, $this->source("(int) env('PORT')"))->getId());
    }

    #[Test]
    public function walks_a_literal_array_source_with_the_runtime_keys(): void
    {
        $type = ConfigValueReflector::reflect(
            ['token' => null, 'region' => 'eu', 'added_by_provider' => null],
            $this->source("['token' => env('TOKEN'), 'region' => 'eu', 'dropped_by_provider' => env('X')]"),
        );

        $this->assertSame('array{added_by_provider: mixed, region: string, token: null|string}', $type->getId());
    }

    #[Test]
    public function a_first_class_env_callable_is_a_stored_closure(): void
    {
        $closure = \Closure::fromCallable('strlen');

        $this->assertSame('Closure', ConfigValueReflector::reflect($closure, $this->source('env(...)'))->getId());
        $this->assertSame('Closure', ConfigValueReflector::reflect($closure, $this->source('\Illuminate\Support\Env::get(...)'))->getId());
    }

    #[Test]
    public function types_an_env_reading_array_it_cannot_walk_as_mixed(): void
    {
        $type = ConfigValueReflector::reflect([], $this->source("[...explode(',', (string) env('KEYS'))]"));

        $this->assertSame('mixed', $type->getId());
    }

    private function source(string $expression): ConfigSource
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php return {$expression};");
        $this->assertNotNull($statements);
        (new NodeTraverser(new NameResolver()))->traverse($statements);

        $return = $statements[0] ?? null;
        $this->assertInstanceOf(Return_::class, $return);
        $this->assertInstanceOf(Expr::class, $return->expr);

        return new ConfigSource(ConfigSourceIndex::fromDirectories(null), 'demo', [$return->expr]);
    }

    #[Test]
    public function reflects_bool_to_general_bool_not_literal(): void
    {
        $type = ConfigValueReflector::reflect(false);
        $this->assertSame('bool', $type->getId());
    }

    #[Test]
    public function reflects_string_to_general_string_not_literal(): void
    {
        $type = ConfigValueReflector::reflect('Laravel');
        $this->assertSame('string', $type->getId());
    }

    #[Test]
    public function reflects_empty_array_to_empty_array_atomic(): void
    {
        $type = ConfigValueReflector::reflect([]);
        $this->assertTrue($type->equals(Type::getEmptyArray()));
    }

    #[Test]
    public function reflects_list_to_keyed_array_is_list(): void
    {
        $type = ConfigValueReflector::reflect(['a', 'b']);
        $atomic = $type->getSingleAtomic();

        $this->assertInstanceOf(TKeyedArray::class, $atomic);
        $this->assertTrue($atomic->is_list);
        $this->assertCount(2, $atomic->properties);
    }

    #[Test]
    public function reflects_closure_value_to_Closure_named_object(): void
    {
        // Repository::get returns the closure object verbatim — `value()` only
        // runs on the $default branch inside Arr::get, never on stored values.
        $closure = static fn(): string => 'x';
        $type = ConfigValueReflector::reflect($closure);
        $atomic = $type->getSingleAtomic();

        $this->assertInstanceOf(TNamedObject::class, $atomic);
        $this->assertSame(\Closure::class, $atomic->value);
    }

    #[Test]
    public function degrades_to_mixed_array_at_max_depth(): void
    {
        // Build a tree deeper than MAX_DEPTH so the deepest level hits the
        // degradation arm. The outer levels remain TKeyedArray; only the leaf
        // beyond MAX_DEPTH collapses to array<array-key, mixed>.
        $leaf = ['leaf' => 'value'];
        $tree = $leaf;
        for ($i = 0; $i < ConfigValueReflector::MAX_DEPTH + 1; $i++) {
            $tree = ['n' => $tree];
        }

        $type = ConfigValueReflector::reflect($tree);

        // Walk the keyed arrays until we hit the degraded floor. Assert exact
        // id at the floor — the sibling key-cap test uses the same shape.
        $current = $type->getSingleAtomic();
        while ($current instanceof \Psalm\Type\Atomic\TKeyedArray) {
            $current = $current->properties['n']?->getSingleAtomic() ?? $current->properties['leaf']->getSingleAtomic();
        }

        $this->assertInstanceOf(\Psalm\Type\Atomic\TArray::class, $current);
        $this->assertSame('array<array-key, mixed>', (new \Psalm\Type\Union([$current]))->getId());
    }

    #[Test]
    public function degrades_to_mixed_array_when_keys_exceed_cap(): void
    {
        $wide = [];
        for ($i = 0; $i <= ConfigValueReflector::MAX_KEYS_PER_LEVEL; $i++) {
            $wide["key_{$i}"] = $i;
        }

        $type = ConfigValueReflector::reflect($wide);
        $this->assertSame('array<array-key, mixed>', $type->getId());
    }

    #[Test]
    public function degrades_when_total_property_budget_exhausted(): void
    {
        // Branching shape that fits within MAX_DEPTH and the per-level cap, but
        // explodes total atomic count without the cross-level budget. Each
        // top-level entry holds a smaller keyed array; together they cross
        // MAX_TOTAL_PROPERTIES.
        $branch = [];
        for ($i = 0; $i < 32; $i++) {
            $branch["k_{$i}"] = $i;
        }

        $tree = [];
        for ($i = 0; $i < 32; $i++) {
            // 32 branches × 32 leaves each = 1024 > MAX_TOTAL_PROPERTIES (512).
            $tree["b_{$i}"] = $branch;
        }

        $type = ConfigValueReflector::reflect($tree);

        // The top-level shape is still keyed (within budget for level 0),
        // but at least one sub-tree degrades to array<array-key, mixed>.
        $id = $type->getId();
        $this->assertStringContainsString('array<array-key, mixed>', $id, 'Budget exhaustion must surface as array<array-key, mixed> somewhere in the tree.');
    }
}
