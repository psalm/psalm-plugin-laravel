<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotTableResolver;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AppendsOrderModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AttributeConfiguredModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ScalarFieldsModel;

/**
 * The resolver replays Laravel's pivot-table derivation without booting models, so each case pins the
 * runtime rule it mirrors: `HasRelationships::belongsToMany()` / `joiningTable()` / `morphToMany()` and
 * `BelongsToMany::resolveTableName()`.
 */
#[CoversClass(PivotTableResolver::class)]
final class PivotTableResolverTest extends TestCase
{
    #[Test]
    public function default_table_is_the_alphabetically_sorted_snake_segments(): void
    {
        $this->assertSame(
            'appends_order_model_scalar_fields_model',
            PivotTableResolver::forBelongsToMany(null, ScalarFieldsModel::class, AppendsOrderModel::class),
        );
        // Sorting makes the name independent of which side declares the relation.
        $this->assertSame(
            'appends_order_model_scalar_fields_model',
            PivotTableResolver::forBelongsToMany(null, AppendsOrderModel::class, ScalarFieldsModel::class),
        );
    }

    #[Test]
    public function overridden_joining_table_segment_is_honored_on_both_sides(): void
    {
        $declaring = new class extends Model {
            #[\Override]
            public function joiningTableSegment(): string
            {
                return 'zeta';
            }
        };
        $related = new class extends Model {
            #[\Override]
            public function joiningTableSegment(): string
            {
                return 'alpha';
            }
        };

        $this->assertSame(
            'scalar_fields_model_zeta',
            PivotTableResolver::forBelongsToMany(null, $declaring::class, ScalarFieldsModel::class),
        );
        $this->assertSame(
            'alpha_scalar_fields_model',
            PivotTableResolver::forBelongsToMany(null, ScalarFieldsModel::class, $related::class),
        );
    }

    #[Test]
    public function explicit_literal_table_is_returned_verbatim(): void
    {
        $this->assertSame(
            'team_members',
            PivotTableResolver::forBelongsToMany('team_members', ScalarFieldsModel::class, AppendsOrderModel::class),
        );
    }

    #[Test]
    public function empty_explicit_table_is_not_a_usable_table(): void
    {
        // Laravel only defaults on null, so '' would be queried as-is.
        $this->assertNull(
            PivotTableResolver::forBelongsToMany('', ScalarFieldsModel::class, AppendsOrderModel::class),
        );
    }

    #[Test]
    public function model_class_string_table_resolves_to_that_models_table(): void
    {
        $this->assertSame(
            'scalar_fields_models',
            PivotTableResolver::forBelongsToMany(ScalarFieldsModel::class, AppendsOrderModel::class, AppendsOrderModel::class),
        );
    }

    #[Test]
    public function model_class_string_table_honors_the_table_attribute(): void
    {
        if (!\class_exists(Table::class)) {
            $this->markTestSkipped('#[Table] requires Laravel 13');
        }

        $this->assertSame(
            'attr_table',
            PivotTableResolver::forBelongsToMany(AttributeConfiguredModel::class, ScalarFieldsModel::class, AppendsOrderModel::class),
        );
    }

    #[Test]
    public function backslashed_string_that_is_not_a_model_stays_literal(): void
    {
        $this->assertSame(
            \stdClass::class,
            PivotTableResolver::forBelongsToMany(\stdClass::class, ScalarFieldsModel::class, AppendsOrderModel::class),
        );
        $this->assertSame(
            'App\\NoSuchPivotModel',
            PivotTableResolver::forBelongsToMany('App\\NoSuchPivotModel', ScalarFieldsModel::class, AppendsOrderModel::class),
        );
    }

    #[Test]
    public function an_unloadable_declaring_model_yields_null(): void
    {
        /** @var class-string<Model> $missing */
        $missing = 'App\\Models\\DefinitelyNotThere';

        $this->assertNull(PivotTableResolver::forBelongsToMany(null, $missing, ScalarFieldsModel::class));
    }

    #[Test]
    public function morph_default_pluralizes_only_the_last_word(): void
    {
        $this->assertSame('taggables', PivotTableResolver::forMorphToMany(null, 'taggable'));
        $this->assertSame('model_has_roles', PivotTableResolver::forMorphToMany(null, 'model_has_role'));
    }

    #[Test]
    public function morph_explicit_table_wins_over_the_default(): void
    {
        $this->assertSame('tag_links', PivotTableResolver::forMorphToMany('tag_links', 'taggable'));
        $this->assertSame('scalar_fields_models', PivotTableResolver::forMorphToMany(ScalarFieldsModel::class, 'taggable'));
    }

    #[Test]
    public function morph_empty_table_takes_the_default_like_laravels_falsy_check(): void
    {
        $this->assertSame('taggables', PivotTableResolver::forMorphToMany('', 'taggable'));
    }
}
