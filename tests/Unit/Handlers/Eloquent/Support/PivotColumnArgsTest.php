<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotColumnArgs;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ScalarFieldsModel;

#[CoversClass(PivotColumnArgs::class)]
final class PivotColumnArgsTest extends TestCase
{
    // ---------------------------------------------------------------------
    // withPivot()
    // ---------------------------------------------------------------------

    #[Test]
    public function with_pivot_reads_variadic_string_literals(): void
    {
        $this->assertSame(['role', 'level'], PivotColumnArgs::withPivot([self::str('role'), self::str('level')]));
    }

    #[Test]
    public function with_pivot_array_first_arg_is_the_whole_list_and_ignores_later_args(): void
    {
        $args = [
            new Arg(new Array_([new ArrayItem(new String_('role')), new ArrayItem(new String_('level'))])),
            // Runtime `is_array($columns) ? $columns : func_get_args()` never looks at these.
            self::str('ignored'),
            new Arg(new Variable('alsoIgnored')),
        ];

        $this->assertSame(['role', 'level'], PivotColumnArgs::withPivot($args));
    }

    /**
     * @param list<Arg|VariadicPlaceholder> $args
     */
    #[Test]
    #[DataProvider('undecidableWithPivotArgs')]
    public function with_pivot_declines_what_it_cannot_prove(array $args): void
    {
        $this->assertNull(PivotColumnArgs::withPivot($args));
    }

    /**
     * @return iterable<string, array{list<Arg|VariadicPlaceholder>}>
     */
    public static function undecidableWithPivotArgs(): iterable
    {
        yield 'no args' => [[]];
        yield 'unpack' => [[new Arg(new Variable('columns'), false, true)]];
        yield 'unpack after a literal' => [[self::str('role'), new Arg(new Variable('columns'), false, true)]];
        yield 'named arg' => [[new Arg(new String_('role'), false, false, [], new Identifier('columns'))]];
        yield 'named arg after a literal' => [[self::str('role'), new Arg(new String_('level'), false, false, [], new Identifier('columns'))]];
        yield 'non-literal arg' => [[new Arg(new Variable('column'))]];
        yield 'non-literal arg after a literal' => [[self::str('role'), new Arg(new Variable('column'))]];
        yield 'empty-string name' => [[self::str('')]];
        yield 'empty-string name after a literal' => [[self::str('role'), self::str('')]];
        yield 'first-class callable' => [[new VariadicPlaceholder()]];
        yield 'empty array' => [[new Arg(new Array_([]))]];
        yield 'keyed array item' => [[new Arg(new Array_([new ArrayItem(new String_('role'), new String_('key'))]))]];
        yield 'unpacked array item' => [[new Arg(new Array_([new ArrayItem(new Variable('more'), null, false, [], true)]))]];
        yield 'by-reference array item' => [[new Arg(new Array_([new ArrayItem(new String_('role'), null, true)]))]];
        yield 'non-literal array item' => [[new Arg(new Array_([new ArrayItem(new Variable('column'))]))]];
        yield 'empty-string array item' => [[new Arg(new Array_([new ArrayItem(new String_(''))]))]];
    }

    // ---------------------------------------------------------------------
    // withTimestamps()
    // ---------------------------------------------------------------------

    #[Test]
    public function with_timestamps_without_args_selects_the_parents_default_names(): void
    {
        $this->assertSame(['created_at', 'updated_at'], PivotColumnArgs::withTimestamps([], ScalarFieldsModel::class));
    }

    #[Test]
    public function with_timestamps_explicit_null_selects_the_parents_default_names(): void
    {
        $args = [new Arg(self::constant('null')), new Arg(self::constant('NULL'))];

        $this->assertSame(['created_at', 'updated_at'], PivotColumnArgs::withTimestamps($args, ScalarFieldsModel::class));
    }

    #[Test]
    public function with_timestamps_string_literals_name_the_columns(): void
    {
        $args = [self::str('joined_at'), self::str('left_at')];

        $this->assertSame(['joined_at', 'left_at'], PivotColumnArgs::withTimestamps($args, ScalarFieldsModel::class));
    }

    #[Test]
    public function with_timestamps_false_skips_the_slot(): void
    {
        $this->assertSame(
            ['updated_at'],
            PivotColumnArgs::withTimestamps([new Arg(self::constant('false'))], ScalarFieldsModel::class),
        );
        $this->assertSame(
            ['created_at'],
            PivotColumnArgs::withTimestamps([self::str('created_at'), new Arg(self::constant('false'))], null),
        );
        $this->assertSame(
            [],
            PivotColumnArgs::withTimestamps([new Arg(self::constant('false')), new Arg(self::constant('false'))], null),
        );
    }

    #[Test]
    public function with_timestamps_accepts_named_args(): void
    {
        $updatedOnly = [new Arg(new String_('changed_at'), false, false, [], new Identifier('updatedAt'))];
        $both = [
            new Arg(new String_('left_at'), false, false, [], new Identifier('updatedAt')),
            new Arg(new String_('joined_at'), false, false, [], new Identifier('createdAt')),
        ];

        // The omitted `createdAt` slot still takes the parent's default.
        $this->assertSame(['created_at', 'changed_at'], PivotColumnArgs::withTimestamps($updatedOnly, ScalarFieldsModel::class));
        // Output order follows the parameters, not the call-site order.
        $this->assertSame(['joined_at', 'left_at'], PivotColumnArgs::withTimestamps($both, null));
    }

    /**
     * @param list<Arg|VariadicPlaceholder> $args
     */
    #[Test]
    #[DataProvider('undecidableWithTimestampsArgs')]
    public function with_timestamps_declines_what_it_cannot_prove(array $args): void
    {
        $this->assertNull(PivotColumnArgs::withTimestamps($args, ScalarFieldsModel::class));
    }

    /**
     * @return iterable<string, array{list<Arg|VariadicPlaceholder>}>
     */
    public static function undecidableWithTimestampsArgs(): iterable
    {
        yield 'unpack' => [[new Arg(new Variable('names'), false, true)]];
        yield 'unknown named arg' => [[new Arg(new String_('x'), false, false, [], new Identifier('deletedAt'))]];
        yield 'named arg with the wrong case' => [[new Arg(new String_('x'), false, false, [], new Identifier('updatedat'))]];
        yield 'repeated named arg' => [[
            new Arg(new String_('x'), false, false, [], new Identifier('createdAt')),
            new Arg(new String_('y'), false, false, [], new Identifier('createdAt')),
        ]];
        yield 'positional and named arg for the same slot' => [[
            self::str('x'),
            new Arg(new String_('y'), false, false, [], new Identifier('createdAt')),
        ]];
        yield 'extra positional arg' => [[self::str('x'), self::str('y'), self::str('z')]];
        yield 'non-literal arg' => [[new Arg(new Variable('name'))]];
        yield 'true' => [[new Arg(self::constant('true'))]];
        yield 'empty-string name' => [[self::str('')]];
        yield 'first-class callable' => [[new VariadicPlaceholder()]];
    }

    #[Test]
    public function with_timestamps_declines_a_defaulted_slot_when_the_parent_is_unknown(): void
    {
        $this->assertNull(PivotColumnArgs::withTimestamps([], null));
        $this->assertNull(PivotColumnArgs::withTimestamps([self::str('joined_at')], null));
        $this->assertNull(PivotColumnArgs::withTimestamps([new Arg(self::constant('false'))], null));
    }

    #[Test]
    public function with_timestamps_explicit_names_need_no_parent(): void
    {
        $this->assertSame(
            ['joined_at', 'left_at'],
            PivotColumnArgs::withTimestamps([self::str('joined_at'), self::str('left_at')], null),
        );
    }

    #[Test]
    public function with_timestamps_follows_the_parents_custom_names(): void
    {
        $parent = new class extends Model {
            public const CREATED_AT = 'made_at';

            public const UPDATED_AT = 'changed_at';
        };

        $this->assertSame(['made_at', 'changed_at'], PivotColumnArgs::withTimestamps([], $parent::class));
        $this->assertSame(['made_at', 'left_at'], PivotColumnArgs::withTimestamps([new Arg(self::constant('null')), self::str('left_at')], $parent::class));
    }

    #[Test]
    public function with_timestamps_declines_a_defaulted_slot_whose_parent_name_is_null(): void
    {
        $noCreatedAt = new class extends Model {
            public const CREATED_AT = null;
        };
        $noUpdatedAt = new class extends Model {
            public const UPDATED_AT = null;
        };

        $this->assertNull(PivotColumnArgs::withTimestamps([], $noCreatedAt::class));
        $this->assertNull(PivotColumnArgs::withTimestamps([], $noUpdatedAt::class));
        // Per slot: an explicit name or `false` for the missing column leaves nothing to default.
        $this->assertSame(['joined_at', 'updated_at'], PivotColumnArgs::withTimestamps([self::str('joined_at')], $noCreatedAt::class));
        $this->assertSame(['updated_at'], PivotColumnArgs::withTimestamps([new Arg(self::constant('false'))], $noCreatedAt::class));
        $this->assertSame(['created_at'], PivotColumnArgs::withTimestamps([self::str('created_at'), new Arg(self::constant('false'))], $noUpdatedAt::class));
    }

    private static function str(string $value): Arg
    {
        return new Arg(new String_($value));
    }

    private static function constant(string $name): ConstFetch
    {
        return new ConstFetch(new Name($name));
    }
}
