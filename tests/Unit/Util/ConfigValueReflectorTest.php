<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Util\ConfigValueReflector;
use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;

#[CoversClass(ConfigValueReflector::class)]
final class ConfigValueReflectorTest extends TestCase
{
    #[Test]
    public function reflects_null_to_TNull(): void
    {
        $this->assertTrue(ConfigValueReflector::reflect(null)->isNull());
    }

    #[Test]
    public function reflects_bool_to_general_bool_not_literal(): void
    {
        $type = ConfigValueReflector::reflect(false);
        $this->assertSame('bool', $type->getId());
    }

    #[Test]
    public function reflects_int_to_general_int_not_literal(): void
    {
        $type = ConfigValueReflector::reflect(42);
        $this->assertSame('int', $type->getId());
    }

    #[Test]
    public function reflects_float_to_general_float(): void
    {
        $type = ConfigValueReflector::reflect(3.14);
        $this->assertSame('float', $type->getId());
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
    public function reflects_keyed_array_preserves_string_keys(): void
    {
        $type = ConfigValueReflector::reflect(['name' => 'Laravel', 'debug' => true]);
        $atomic = $type->getSingleAtomic();

        $this->assertInstanceOf(TKeyedArray::class, $atomic);
        $this->assertFalse($atomic->is_list);
        $this->assertArrayHasKey('name', $atomic->properties);
        $this->assertArrayHasKey('debug', $atomic->properties);
    }

    #[Test]
    public function reflects_object_to_named_object_atomic(): void
    {
        $type = ConfigValueReflector::reflect(new \stdClass());
        $atomic = $type->getSingleAtomic();

        $this->assertInstanceOf(TNamedObject::class, $atomic);
        $this->assertSame(\stdClass::class, $atomic->value);
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

    #[Test]
    public function nested_arrays_recurse_within_depth_cap(): void
    {
        $shallow = ['outer' => ['inner' => 'leaf']];
        $type = ConfigValueReflector::reflect($shallow);
        $atomic = $type->getSingleAtomic();

        $this->assertInstanceOf(TKeyedArray::class, $atomic);
        $inner = $atomic->properties['outer'];
        $innerAtomic = $inner->getSingleAtomic();
        $this->assertInstanceOf(TKeyedArray::class, $innerAtomic);
    }
}
