<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelAggregatePropertyHandler;

#[CoversClass(ModelAggregatePropertyHandler::class)]
final class ModelAggregatePropertyHandlerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // couldBeAggregate()
    // -------------------------------------------------------------------------
    /** @return \Iterator<string, array{string, bool}> */
    public static function couldBeAggregateProvider(): \Iterator
    {
        // exact suffixes
        yield 'contacts_count' => ['contacts_count', true];
        yield 'contacts_exists' => ['contacts_exists', true];
        // column-including suffixes
        yield 'contacts_sum_amount' => ['contacts_sum_amount', true];
        yield 'contacts_avg_price' => ['contacts_avg_price', true];
        yield 'contacts_min_created_at' => ['contacts_min_created_at', true];
        yield 'contacts_max_updated_at' => ['contacts_max_updated_at', true];
        // non-aggregate properties
        yield 'email' => ['email', false];
        yield 'name' => ['name', false];
        yield 'created_at' => ['created_at', false];
        // partial match — suffix without underscore prefix
        yield 'count' => ['count', false];
        yield 'sum_amount' => ['sum_amount', false];
        // suffix in wrong position
        yield 'count_contacts' => ['count_contacts', false];
    }

    #[Test]
    #[DataProvider('couldBeAggregateProvider')]
    public function couldBeAggregate_returns_expected(string $property, bool $expected): void
    {
        $method = new \ReflectionMethod(ModelAggregatePropertyHandler::class, 'couldBeAggregate');

        $this->assertSame($expected, $method->invoke(null, $property));
    }

    // -------------------------------------------------------------------------
    // snakeToCamelCase()
    // -------------------------------------------------------------------------
    /** @return \Iterator<string, array{string, string}> */
    public static function snakeToCamelCaseProvider(): \Iterator
    {
        yield 'single word' => ['contacts', 'contacts'];
        yield 'two words' => ['work_orders', 'workOrders'];
        yield 'three words' => ['work_order_items', 'workOrderItems'];
        yield 'already camelCase' => ['workOrders', 'workOrders'];
        yield 'leading underscore' => ['_contacts', 'contacts'];
    }

    #[Test]
    #[DataProvider('snakeToCamelCaseProvider')]
    public function snakeToCamelCase_converts_correctly(string $input, string $expected): void
    {
        $method = new \ReflectionMethod(ModelAggregatePropertyHandler::class, 'snakeToCamelCase');

        $this->assertSame($expected, $method->invoke(null, $input));
    }
}
