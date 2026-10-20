<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;

/**
 * The AST readers behind the pivot column shapes (#1944): `withPivot()` columns, `withTimestamps()`
 * arguments and where a pivot-aware factory keeps its table. Whatever is not a literal must come back as
 * "unknown", never as a guess.
 */
#[CoversClass(RelationMethodParser::class)]
final class RelationMethodParserPivotArgumentsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function withPivotCalls(): iterable
    {
        yield 'variadic' => ["withPivot('a', 'b')", ['a', 'b']];
        yield 'array form' => ["withPivot(['a', 'b'])", ['a', 'b']];
        yield 'array form reads only the first argument' => ["withPivot(['a'], 'b')", ['a']];
        yield 'keyed array claims nothing' => ["withPivot(['x' => 'a', 'y' => 'b'])", []];
        yield 'non-literal arguments are skipped' => ["withPivot('a', \$b, 'c' . 'd', 1)", ['a']];
        yield 'non-literal array items are skipped' => ["withPivot(['a', \$b, ...\$rest])", ['a']];
        yield 'unpacked argument is skipped' => ["withPivot('a', ...\$rest)", ['a']];
        yield 'named columns argument' => ["withPivot(columns: 'a')", ['a']];
        yield 'named columns array' => ["withPivot(columns: ['a', 'b'])", ['a', 'b']];
        yield 'other named argument is skipped' => ["withPivot(other: 'a')", []];
        yield 'variable first argument may be the array Laravel reads' => ['withPivot($columns, \'role\')', []];
        yield 'call first argument may be an array' => ["withPivot(\$this->columns(), 'role')", []];
        yield 'unpacked first argument is unknown' => ["withPivot(...\$rest)", []];
        yield 'empty string is no column' => ["withPivot('')", []];
        yield 'explicit integer keys are list entries' => ["withPivot([0 => 'a', 'b'])", ['a', 'b']];
        yield 'keyed entries claim nothing' => ["withPivot(['k' => 'a', 'b'])", ['b']];
        yield 'nested array is skipped' => ["withPivot('a', ['b'])", ['a']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('withPivotCalls')]
    public function it_reads_the_literal_with_pivot_columns(string $call, array $expected): void
    {
        $this->assertSame($expected, RelationMethodParser::withPivotColumns($this->call($call)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function withPivotHiddenCalls(): iterable
    {
        yield 'list array' => ["withPivot(['a', 'b'])", false];
        yield 'integer keys' => ["withPivot([0 => 'a', 1 => 'b'])", false];
        yield 'string key' => ["withPivot(['k' => 'created_at', 'b'])", true];
        yield 'spread' => ['withPivot([...$rest, \'b\'])', true];
        yield 'variadic strings' => ["withPivot('a', 'b')", false];
    }

    #[Test]
    #[DataProvider('withPivotHiddenCalls')]
    public function it_tells_whether_a_with_pivot_array_hides_columns(string $call, bool $expected): void
    {
        $this->assertSame($expected, RelationMethodParser::withPivotHidesColumns($this->call($call)));
    }

    /**
     * @return iterable<string, array{string, array{created: string|false|null, updated: string|false|null}}>
     */
    public static function withTimestampsCalls(): iterable
    {
        yield 'no arguments' => ['withTimestamps()', ['created' => null, 'updated' => null]];
        yield 'both names' => ["withTimestamps('a', 'b')", ['created' => 'a', 'updated' => 'b']];
        yield 'explicit null is the parent column' => ["withTimestamps(null, 'b')", ['created' => null, 'updated' => 'b']];
        yield 'false drops the column' => ["withTimestamps(false, 'b')", ['created' => false, 'updated' => 'b']];
        yield 'dynamic argument is unknown' => ['withTimestamps($a)', ['created' => false, 'updated' => null]];
        yield 'named arguments' => ["withTimestamps(updatedAt: 'b')", ['created' => null, 'updated' => 'b']];
        yield 'unknown named argument is ignored' => ["withTimestamps(other: 'b')", ['created' => null, 'updated' => null]];
        yield 'unpack makes both unknown' => ['withTimestamps(...$args)', ['created' => false, 'updated' => false]];
        yield 'empty name is unknown' => ["withTimestamps('')", ['created' => false, 'updated' => null]];
    }

    /**
     * @param array{created: string|false|null, updated: string|false|null} $expected
     */
    #[Test]
    #[DataProvider('withTimestampsCalls')]
    public function it_reads_the_with_timestamps_arguments(string $call, array $expected): void
    {
        $this->assertSame($expected, RelationMethodParser::withTimestampsArgs($this->call($call)));
    }

    /**
     * @return iterable<string, array{string, ?array{kind: string, value: string}}>
     */
    public static function factoryCalls(): iterable
    {
        yield 'default table' => ['belongsToMany(User::class)', ['kind' => 'default', 'value' => '']];
        yield 'null table is the default' => ['belongsToMany(User::class, null)', ['kind' => 'default', 'value' => '']];
        yield 'literal table' => ["belongsToMany(User::class, 'team_user')", ['kind' => 'literal', 'value' => 'team_user']];
        yield 'named table' => ["belongsToMany(User::class, foreignPivotKey: 'k', table: 'team_user')", ['kind' => 'literal', 'value' => 'team_user']];
        yield 'model as table' => ['belongsToMany(User::class, Membership::class)', ['kind' => 'model', 'value' => 'Membership']];
        yield 'dynamic table' => ['belongsToMany(User::class, $table)', null];
        yield 'empty table' => ["belongsToMany(User::class, '')", null];
        yield 'unpacked arguments' => ['belongsToMany(...$args)', null];
        yield 'morph default' => ["morphToMany(Tag::class, 'taggable')", ['kind' => 'morph', 'value' => 'taggable']];
        yield 'morph literal table' => ["morphToMany(Tag::class, 'taggable', 'tags_pivot')", ['kind' => 'literal', 'value' => 'tags_pivot']];
        yield 'morph named' => ["morphedByMany(Tag::class, name: 'taggable')", ['kind' => 'morph', 'value' => 'taggable']];
        yield 'dynamic morph name' => ['morphToMany(Tag::class, $name)', null];
        yield 'not a pivot factory' => ['hasMany(Tag::class)', null];
    }

    /**
     * @param ?array{kind: string, value: string} $expected
     */
    #[Test]
    #[DataProvider('factoryCalls')]
    public function it_reads_where_a_factory_keeps_its_pivot_table(string $call, ?array $expected): void
    {
        $call = $this->call($call);
        $name = \strtolower($call->name instanceof \PhpParser\Node\Identifier ? $call->name->name : '');

        $this->assertSame($expected, RelationMethodParser::factoryPivotTable($call, $name, 'Owner', null));
    }

    #[Test]
    public function it_finds_the_factory_at_the_bottom_of_a_chain(): void
    {
        $chain = $this->call("belongsToMany(User::class)->withPivot('a')->where('x', 1)->withTimestamps()");
        $found = RelationMethodParser::findPivotFactoryCall($chain);

        $this->assertNotNull($found);
        $this->assertSame('belongstomany', $found[1]);

        $this->assertNull(RelationMethodParser::findPivotFactoryCall($this->call("members()->withPivot('a')")));
        $this->assertNull(RelationMethodParser::findPivotFactoryCall($this->call("hasMany(Tag::class)->withPivot('a')")));
    }

    private function call(string $expression): MethodCall
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse('<?php $this->' . $expression . ';') ?? [];

        $traverser = new NodeTraverser(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $statement = $stmts[0] ?? null;
        $this->assertInstanceOf(Expression::class, $statement);
        $this->assertInstanceOf(MethodCall::class, $statement->expr);

        return $statement->expr;
    }
}
