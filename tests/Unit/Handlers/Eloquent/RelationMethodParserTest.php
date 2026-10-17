<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AppendsOrderModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ScalarFieldsModel;

/** Unit tests for syntax-level class resolution in RelationMethodParser. */
#[CoversClass(RelationMethodParser::class)]
final class RelationMethodParserTest extends TestCase
{
    // --- resolveClassConstFetch ---

    /**
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/879
     */
    #[Test]
    #[DataProvider('classConstFetchProvider')]
    public function resolve_class_const_fetch(string $classKeyword, string $declaringClass, ?string $parentClass, ?string $expected): void
    {
        $expr = new \PhpParser\Node\Expr\ClassConstFetch(
            new \PhpParser\Node\Name($classKeyword),
            new \PhpParser\Node\Identifier('class'),
        );

        $result = $this->callPrivate('resolveClassConstFetch', $expr, $declaringClass, $parentClass);

        $this->assertSame($expected, $result);
    }

    /** @return iterable<string, array{string, string, ?string, ?string}> */
    public static function classConstFetchProvider(): iterable
    {
        yield 'self resolves to declaring class' => [
            'self', 'App\\Models\\WorkOrderNote', 'Illuminate\\Database\\Eloquent\\Model', 'App\\Models\\WorkOrderNote',
        ];
        yield 'self is case-insensitive' => [
            'SELF', 'App\\Models\\WorkOrderNote', null, 'App\\Models\\WorkOrderNote',
        ];
        yield 'static resolves to declaring class (conservative — see resolveClassConstFetch docblock)' => [
            'static', 'App\\Models\\Tool', 'Illuminate\\Database\\Eloquent\\Model', 'App\\Models\\Tool',
        ];
        yield 'parent resolves to parent class FQCN' => [
            'parent', 'App\\Models\\PowerTool', 'App\\Models\\Tool', 'App\\Models\\Tool',
        ];
        yield 'parent returns null when no parent class is known' => [
            'parent', 'App\\Models\\Tool', null, null,
        ];
        yield 'regular FQCN falls back to toString (no resolvedName attribute)' => [
            'App\\Models\\Vehicle', 'App\\Models\\Customer', null, 'App\\Models\\Vehicle',
        ];
    }

    // --- pivot columns captured from a relation chain ---
    //
    // Argument parsing itself is owned by PivotColumnArgs (and its tests); these cases pin what the
    // parser does with it: per-call columns, merge order, and the model that resolves default names.

    #[Test]
    public function chain_collects_pivot_columns_table_and_accessor(): void
    {
        $related = AppendsOrderModel::class;
        $parsed = $this->parseChain(
            '$this->belongsToMany(\\' . $related . "::class, 'post_tag')->withPivot('role')->withTimestamps('joined_at', false)->as('membership');",
            ScalarFieldsModel::class,
            ScalarFieldsModel::class,
        );

        $this->assertNotNull($parsed);
        $this->assertSame($related, $parsed['relatedModel']);
        $this->assertSame('post_tag', $parsed['pivotTable']);
        // The call nearest the factory runs first.
        $this->assertSame(['role', 'joined_at'], $parsed['pivotColumns']);
        $this->assertSame('membership', $parsed['accessor']);
    }

    #[Test]
    public function unprovable_call_contributes_nothing_but_other_calls_still_count(): void
    {
        $parsed = $this->parseChain(
            '$this->belongsToMany(\\' . AppendsOrderModel::class . '::class)->withPivot($columns)->withPivot(\'level\');',
            ScalarFieldsModel::class,
            ScalarFieldsModel::class,
        );

        $this->assertNotNull($parsed);
        $this->assertSame(['level'], $parsed['pivotColumns']);
    }

    #[Test]
    public function default_timestamp_columns_come_from_the_receiver_model(): void
    {
        $receiver = new class extends Model {
            public const CREATED_AT = 'made_at';

            public const UPDATED_AT = 'changed_at';
        };

        $parsed = $this->parseChain(
            '$this->belongsToMany(\\' . AppendsOrderModel::class . '::class)->withTimestamps();',
            ScalarFieldsModel::class,
            $receiver::class,
        );

        $this->assertNotNull($parsed);
        $this->assertSame(['made_at', 'changed_at'], $parsed['pivotColumns']);
    }

    #[Test]
    public function default_timestamp_columns_of_a_non_model_receiver_are_not_guessed(): void
    {
        $parsed = $this->parseChain(
            '$this->belongsToMany(\\' . AppendsOrderModel::class . "::class, 'post_tag')->withTimestamps()->withPivot('role');",
            ScalarFieldsModel::class,
            'App\\NotAModel',
        );

        $this->assertNotNull($parsed);
        $this->assertSame(['role'], $parsed['pivotColumns']);
    }

    /**
     * The relation body is declared on a base class but runs with `$this` bound to the receiver, so an
     * omitted table argument defaults to the receiver's joining table.
     */
    #[Test]
    public function default_pivot_table_is_derived_from_the_receiver_class(): void
    {
        $declaring = new class extends Model {
            #[\Override]
            public function joiningTableSegment(): string
            {
                return 'base';
            }
        };
        $receiver = new class extends Model {
            #[\Override]
            public function joiningTableSegment(): string
            {
                return 'receiver';
            }
        };
        $code = '$this->belongsToMany(\\' . ScalarFieldsModel::class . '::class);';

        $this->assertSame(
            'receiver_scalar_fields_model',
            $this->parseChain($code, $declaring::class, $receiver::class)['pivotTable'] ?? null,
        );
        $this->assertSame(
            'base_scalar_fields_model',
            $this->parseChain($code, $declaring::class, $declaring::class)['pivotTable'] ?? null,
        );
    }

    /**
     * The call nearest the factory runs first, so its columns come first; repeats keep the first position.
     */
    #[Test]
    public function pivot_columns_merge_in_source_order(): void
    {
        $merged = $this->callPrivate('mergePivotColumns', [], [['c'], ['b', 'a'], ['a', 'z']]);

        $this->assertSame(['a', 'z', 'b', 'c'], $merged);
    }

    // --- pivot table resolution ---

    #[Test]
    #[DataProvider('pivotTableProvider')]
    public function resolve_pivot_table(string $factory, string $call, ?string $expected): void
    {
        $result = $this->callPrivate(
            'resolvePivotTable',
            $this->parseCall($call),
            $factory,
            ScalarFieldsModel::class,
            null,
            ScalarFieldsModel::class,
            AppendsOrderModel::class,
        );

        $this->assertSame($expected, $result);
    }

    /**
     * Concrete models make the omitted-table default resolvable, so a null below proves the argument
     * gate fired rather than the resolver declining the abstract `Model`.
     *
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function pivotTableProvider(): iterable
    {
        yield 'belongsToMany omitted table resolves the default (control)' => ['belongstomany', '$this->belongsToMany(Tag::class);', 'appends_order_model_scalar_fields_model'];
        yield 'belongsToMany explicit null table resolves the default' => ['belongstomany', '$this->belongsToMany(Tag::class, null);', 'appends_order_model_scalar_fields_model'];
        yield 'belongsToMany literal table' => ['belongstomany', "\$this->belongsToMany(Tag::class, 'post_tag');", 'post_tag'];
        yield 'belongsToMany named table' => ['belongstomany', "\$this->belongsToMany(related: Tag::class, table: 'post_tag');", 'post_tag'];
        yield 'belongsToMany dynamic table' => ['belongstomany', '$this->belongsToMany(Tag::class, $table);', null];
        yield 'belongsToMany spread arguments' => ['belongstomany', '$this->belongsToMany(...$args);', null];
        yield 'morphToMany literal table' => ['morphtomany', "\$this->morphToMany(Tag::class, 'taggable', 'tag_links');", 'tag_links'];
        yield 'morphToMany default table from the morph name' => ['morphtomany', "\$this->morphToMany(Tag::class, 'taggable');", 'taggables'];
        yield 'morphToMany named name and table' => ['morphtomany', "\$this->morphToMany(Tag::class, table: 'tag_links', name: 'taggable');", 'tag_links'];
        yield 'morphedByMany default table' => ['morphedbymany', "\$this->morphedByMany(Post::class, 'taggable');", 'taggables'];
        yield 'morphToMany dynamic name' => ['morphtomany', '$this->morphToMany(Tag::class, $name);', null];
        yield 'morphToMany dynamic table' => ['morphtomany', "\$this->morphToMany(Tag::class, 'taggable', \$table);", null];
    }

    /**
     * Walk an expression down to its factory call the way parse() does (no Codebase needed).
     *
     * @return ?array{relationClass: class-string, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, pivotTable: ?non-empty-string, pivotColumns: list<non-empty-string>, accessor: ?string, nullable: bool}
     */
    private function parseChain(string $code, string $declaringClass, string $receiverClass): ?array
    {
        $pivotModel = null;
        $accessor = null;
        $chain = [];
        $pivotColumnCalls = [];

        $ref = new \ReflectionMethod(RelationMethodParser::class, 'findRelationCallInExpr');

        /** @psalm-var ?array{relationClass: class-string, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, pivotTable: ?non-empty-string, pivotColumns: list<non-empty-string>, accessor: ?string, nullable: bool} $parsed */
        $parsed = $ref->invokeArgs(null, [
            $this->parseCall($code),
            $declaringClass,
            null,
            $receiverClass,
            &$pivotModel,
            &$accessor,
            &$chain,
            &$pivotColumnCalls,
        ]);

        return $parsed;
    }

    private function parseCall(string $code): \PhpParser\Node\Expr\MethodCall
    {
        $stmts = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $code);
        $stmt = $stmts[0] ?? null;

        $this->assertInstanceOf(\PhpParser\Node\Stmt\Expression::class, $stmt);
        $this->assertInstanceOf(\PhpParser\Node\Expr\MethodCall::class, $stmt->expr);

        return $stmt->expr;
    }

    /**
     * Call a private static method on RelationMethodParser via reflection.
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(RelationMethodParser::class, $method);

        return $ref->invoke(null, ...$args);
    }
}
