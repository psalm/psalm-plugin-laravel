<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\ModelMetadata;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelInstancePreparer;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\CastFlavour;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\CastsMethodModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\KeyTypeInitializerOrderModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ObjectCastOverriddenByCastsMethodModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TableKeyOnlyModel;

/**
 * Drives the replay directly — no registry, no Codebase — which is what the Psalm-free boundary buys.
 * {@see ModelMetadataRegistryTest} covers the same behaviour end-to-end through warm-up.
 *
 * @internal
 */
#[CoversClass(ModelInstancePreparer::class)]
final class ModelInstancePreparerTest extends TestCase
{
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
    public function an_object_cast_the_casts_method_overrides_does_not_break_the_replay(): void
    {
        // Laravel normalizes array_merge($casts, casts()) and casts() WINS on collisions, so this model
        // constructs fine — the enum never reaches the is_object arm. The mirror sees the declared half alone
        // and would throw, taking all four instance-derived sections with it. Master gets this right
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

        // #[Table(key:)] with no name, on a class that INHERITS $table. The table half goes through the
        // `$declaresTable` force-branch: Laravel nulls the inherited name so getTable() re-derives from the
        // class name — which is the case for invoking the real method rather than reproducing it, and why
        // this reads a runtime oracle instead of a literal.
        $this->assertSame(
            (new TableKeyOnlyModel())->getTable(),
            $this->prepare(TableKeyOnlyModel::class)->getTable(),
        );

        // The key half has no such branch: it reaches the key on every supported release.
        $this->assertSame('uuid', $this->prepare(TableKeyOnlyModel::class)->getKeyName());
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
