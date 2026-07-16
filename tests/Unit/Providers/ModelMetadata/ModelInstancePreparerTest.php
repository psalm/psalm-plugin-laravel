<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\ModelMetadata;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelInstancePreparer;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AppendsOrderModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\CustomDeletedAtModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\SectionFailureModel;
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
    public function framework_concern_initializer_is_mirrored_not_invoked(): void
    {
        // Real initializeSoftDeletes() writes $casts[getDeletedAtColumn()]; the mirror no-ops and leaves that
        // cast to computeCasts(). So invoking framework initializers instead of mirroring leaves it behind.
        $casts = new \ReflectionProperty(Model::class, 'casts');

        // Pin that Laravel still writes the cast; otherwise [] would not distinguish mirroring from invoking.
        $this->assertSame(['archived_at' => 'datetime'], $casts->getValue(new CustomDeletedAtModel()));
        $this->assertSame([], $casts->getValue($this->prepare(CustomDeletedAtModel::class)));
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
