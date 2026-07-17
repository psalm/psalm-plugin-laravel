<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\ModelMetadata;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelInstancePreparer;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\CastFlavour;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AppendsOrderModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ArrayFormCastsModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\CastsMethodModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\CustomDeletedAtModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\KeyTypeInitializerOrderModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ObjectCastOverriddenByCastsMethodModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\SectionFailureModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TableKeyOnlyModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TraitInitializedConfigModel;

/**
 * Drives the replay directly — no registry, no Codebase — which is what the Psalm-free boundary buys.
 * {@see ModelMetadataRegistryTest} covers the same behaviour end-to-end through warm-up.
 *
 * @internal
 */
#[CoversClass(ModelInstancePreparer::class)]
final class ModelInstancePreparerTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        SectionFailureModel::$failures = [];
    }

    // Also on the way out: the switch is a public static, so a consumer not defending itself would inherit it.
    #[\Override]
    protected function tearDown(): void
    {
        SectionFailureModel::$failures = [];
    }

    #[Test]
    public function user_trait_initializer_is_invoked_on_the_constructor_less_instance(): void
    {
        // initializeMergesTraitConfig() is protected: only the reflection invoke reaches it, since
        // $instance->{$name}() from outside routes to Model::__call() query-builder forwarding.
        $instance = $this->prepare(TraitInitializedConfigModel::class);

        // Key, not whole map: getCasts() also merges [getKeyName() => getKeyType()].
        $this->assertSame(AsArrayObject::class, $instance->getCasts()['meta'] ?? null);
        // Union-merge, not clobber.
        $this->assertSame(['class_fillable', 'trait_fillable'], $instance->getFillable());
    }

    #[Test]
    public function attribute_tagged_trait_initializer_is_invoked(): void
    {
        // #[Initialize] and the bootTraits branch reading it arrive in Laravel 12.22; below that the framework
        // ignores the tag and the replay stays convention-only, so `via_attr` is legitimately absent.
        if (!\class_exists(Initialize::class)) {
            self::markTestSkipped('The #[Initialize] attribute discovery branch requires Laravel >= 12.22.');
        }

        // seedViaAttribute() is non-conventionally named: only the attribute-discovery branch reaches it.
        $this->assertSame(
            AsCollection::class,
            $this->prepare(TraitInitializedConfigModel::class)->getCasts()['via_attr'] ?? null,
        );
    }

    #[Test]
    public function framework_concern_initializer_runs_its_mirror_at_the_right_position(): void
    {
        if (!\class_exists(Appends::class)) {
            self::markTestSkipped('Eloquent PHP class attributes require Laravel >= 13.0.');
        }

        // Runtime construction as oracle, never a literal: getMethods() ranks the user setAppends(['trait_only'])
        // against the framework mergeAppends(#[Appends]) differently per PHP (both survive on 8.5, the replace
        // wins on 8.4), so a literal would pin one PHP line.
        $this->assertSame(
            (new AppendsOrderModel())->getAppends(),
            $this->prepare(AppendsOrderModel::class)->getAppends(),
        );
    }

    #[Test]
    public function framework_concern_initializer_is_invoked(): void
    {
        // Framework initializers are invoked, not mirrored: whatever the installed Laravel does IS the
        // behaviour. initializeSoftDeletes() writing $casts[getDeletedAtColumn()] is the observable proof,
        // and it lands keyed off the DELETED_AT override without the replay knowing that rule.
        $casts = new \ReflectionProperty(Model::class, 'casts');

        // Pin what Laravel itself writes; [] on both sides would not distinguish invoking from mirroring.
        $this->assertSame(['archived_at' => 'datetime'], $casts->getValue(new CustomDeletedAtModel()));
        $this->assertSame(
            $casts->getValue(new CustomDeletedAtModel()),
            $casts->getValue($this->prepare(CustomDeletedAtModel::class)),
        );
    }

    #[Test]
    public function the_has_attributes_initializer_is_mirrored_not_invoked(): void
    {
        // The ONE initializer still mirrored, and this is why: invoking it would execute the user's casts(),
        // whose result the registry already has statically (CastsMethodParser AST-parses it). Not a
        // no-user-code rule — the walk invokes user trait initializers deliberately. Delete the mirror and
        // this fixture's cast appears on the replayed instance.
        $casts = new \ReflectionProperty(Model::class, 'casts');

        // Pin that Laravel itself runs casts(); an absent key on both sides would prove nothing.
        $this->assertSame(['from_casts_method' => 'array'], $casts->getValue(new CastsMethodModel()));
        // The key, not the whole map: a future Laravel initializer writing some OTHER cast is not this
        // test's business, and asserting [] would turn that into a false alarm.
        $this->assertArrayNotHasKey('from_casts_method', $casts->getValue($this->prepare(CastsMethodModel::class)));
    }

    #[Test]
    public function the_has_attributes_mirror_normalizes_the_declared_casts(): void
    {
        // initializeHasAttributes()'s first statement does TWO things, and only one is excused: it runs
        // casts() (skipped — the registry already has it statically) and it normalizes the DECLARED $casts,
        // which involves no casts() at all. Skipping the second dropped the model's whole casts section
        // downstream (#1281).
        $casts = new \ReflectionProperty(Model::class, 'casts');

        // Pin the premise: Laravel collapses both array forms and leaves the string one alone. Without it,
        // the oracle below cannot tell "normalized" from "both sides equally raw".
        $this->assertSame([
            'options' => AsCollection::class . ':' . Collection::class,
            'single' => AsCollection::class,
            'plain_tags' => 'collection',
        ], $casts->getValue(new ArrayFormCastsModel()));

        // Runtime as oracle: the mirror calls Laravel's own normalizer, so whatever the installed release
        // does IS the answer.
        $this->assertSame($casts->getValue(new ArrayFormCastsModel()), $casts->getValue($this->prepare(ArrayFormCastsModel::class)));
    }

    #[Test]
    public function an_object_cast_the_casts_method_overrides_does_not_break_the_replay(): void
    {
        // Laravel normalizes array_merge($casts, casts()) and casts() WINS on collisions, so this model
        // constructs fine — the enum never reaches the is_object arm. The mirror sees the declared half alone
        // and would throw (12.26+), taking all four instance-derived sections with it. Master gets this right
        // by never normalizing, so the throw would be a REGRESSION, not merely an escalation.
        $casts = new \ReflectionProperty(Model::class, 'casts');

        // Pin the premise: the model really is constructable, and casts() really does win.
        $this->assertSame(['flavour' => 'string'], $casts->getValue(new ObjectCastOverriddenByCastsMethodModel()));

        // Left raw rather than normalized or thrown on: computeCasts() holds the AST-parsed casts() and
        // merges it over this, reaching the same answer the constructor did.
        $this->assertSame(
            ['flavour' => CastFlavour::Vanilla],
            $casts->getValue($this->prepare(ObjectCastOverriddenByCastsMethodModel::class)),
        );
    }

    #[Test]
    public function the_model_attributes_phase_runs_after_the_walk(): void
    {
        if (!\class_exists(Table::class)) {
            self::markTestSkipped('Eloquent PHP class attributes require Laravel >= 13.0.');
        }

        // A trait initializer and #[Table(keyType:)] write the same property, and Laravel's `=== 'int'` guard
        // makes the order decide the answer: phase last (runtime) yields 'string', phase hoisted ahead of the
        // walk yields 'int'. Runtime as oracle, never a literal.
        $this->assertSame(
            (new KeyTypeInitializerOrderModel())->getKeyType(),
            $this->prepare(KeyTypeInitializerOrderModel::class)->getKeyType(),
        );

        // Pin the premise: without it both sides agree on the 'int' default and the oracle proves nothing.
        $this->assertSame('string', (new KeyTypeInitializerOrderModel())->getKeyType());
    }

    #[Test]
    public function the_model_attributes_phase_applies_the_table_attribute(): void
    {
        if (!\class_exists(Table::class)) {
            self::markTestSkipped('Eloquent PHP class attributes require Laravel >= 13.0.');
        }

        // #[Table(key:)] with no name, on a class that INHERITS $table. The table half is version-split inside
        // the supported range (13.3-13.5's `??=` keeps the inherited name; 13.6+ force-nulls it so getTable()
        // re-derives) — which is the case for invoking the real method rather than reproducing it, and why
        // this reads a runtime oracle instead of a literal.
        $this->assertSame(
            (new TableKeyOnlyModel())->getTable(),
            $this->prepare(TableKeyOnlyModel::class)->getTable(),
        );

        // The key half has no such split: it reaches the key on every supported release.
        $this->assertSame('uuid', $this->prepare(TableKeyOnlyModel::class)->getKeyName());
    }

    #[Test]
    public function throwing_initializer_propagates_to_the_caller(): void
    {
        // prepare() lets the throw out; the builder's section guard decides what a half-prepared instance backs.
        SectionFailureModel::$failures['trait initializers'] = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deliberate trait initializers failure');

        $this->prepare(SectionFailureModel::class);
    }

    /** @param class-string<Model> $modelFqcn */
    private function prepare(string $modelFqcn): Model
    {
        $reflection = new \ReflectionClass($modelFqcn);
        $instance = $reflection->newInstanceWithoutConstructor();
        ModelInstancePreparer::prepare($reflection, $instance);

        return $instance;
    }
}
