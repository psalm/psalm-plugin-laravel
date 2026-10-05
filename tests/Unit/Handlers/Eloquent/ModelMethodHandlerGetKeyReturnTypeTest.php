<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Models\ConflictingKeyCastModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\Type\Union;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\CollectingProgress;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\IntegerCastModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\SectionFailureModel;

/**
 * getKeyReturnType() is private; the bail branch exercised here (an incomplete
 * SECTION_PRIMARY_KEY) can't be reached through a normal booted-app phpt fixture, since a
 * genuinely broken warm-up there would spill into every other phpt sharing the same app boot.
 */
#[CoversClass(ModelMethodHandler::class)]
final class ModelMethodHandlerGetKeyReturnTypeTest extends TestCase
{
    private ClassLikeStorageProvider $classLikeStorageProvider;

    #[\Override]
    protected function setUp(): void
    {
        ModelMetadataRegistryBuilder::reset();
        SchemaStateProvider::setSchema(new SchemaAggregator());
        SectionFailureModel::$failures = [];
        $this->classLikeStorageProvider = new ClassLikeStorageProvider();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ModelMetadataRegistryBuilder::reset();
    }

    /**
     * Regression guard: a crashed primary-key warm-up must not let getKeyReturnType() fall back
     * to a guessed type. Asserting isComplete() directly (not just the final null) is
     * deliberate — every degraded-warm-up path also defaults primaryKey to a guess, so a test
     * that only checked the return value could pass vacuously against a silently-wrong guard.
     */
    #[Test]
    public function incomplete_primary_key_section_bails_to_stub_fallback(): void
    {
        $codebase = $this->makeCodebase();
        $this->classLikeStorageProvider->create(SectionFailureModel::class);
        SectionFailureModel::$failures = ['primary key' => true];

        ModelMetadataRegistryBuilder::warmUp($codebase, SectionFailureModel::class);

        $metadata = ModelMetadataRegistry::for(SectionFailureModel::class);
        $this->assertNotNull($metadata);
        $this->assertFalse($metadata->isComplete(ModelMetadata::SECTION_PRIMARY_KEY));

        $this->assertNull($this->getKeyReturnType(SectionFailureModel::class));
    }

    /**
     * An unsigned `$table->id()` key reads as `int<0, max>` through the implicit key cast (#1672);
     * getKey() must still narrow to `int` instead of falling back to the stub's `int|string`.
     */
    #[Test]
    public function unsigned_integer_key_narrows_to_int(): void
    {
        $this->seedKeyColumn('integer_cast_models', unsigned: true);
        $codebase = $this->makeCodebase();
        $this->classLikeStorageProvider->create(IntegerCastModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, IntegerCastModel::class);

        $this->assertSame('int', (string) $this->getKeyReturnType(IntegerCastModel::class));
    }

    #[Test]
    public function signed_integer_key_narrows_to_int(): void
    {
        $this->seedKeyColumn('integer_cast_models', unsigned: false);
        $codebase = $this->makeCodebase();
        $this->classLikeStorageProvider->create(IntegerCastModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, IntegerCastModel::class);

        $this->assertSame('int', (string) $this->getKeyReturnType(IntegerCastModel::class));
    }

    /** A key cast that contradicts the int key (`'id' => 'string'`) still declines to the stub fallback. */
    #[Test]
    public function conflicting_key_cast_still_bails_for_unsigned_key(): void
    {
        $this->seedKeyColumn('conflicting_key_cast_models', unsigned: true);
        $codebase = $this->makeCodebase();
        $this->classLikeStorageProvider->create(ConflictingKeyCastModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, ConflictingKeyCastModel::class);

        $this->assertNull($this->getKeyReturnType(ConflictingKeyCastModel::class));
    }

    private function seedKeyColumn(string $tableName, bool $unsigned): void
    {
        $schema = new SchemaAggregator();
        $table = new SchemaTable();
        $table->setColumn(new SchemaColumn('id', SchemaColumn::TYPE_INT, unsigned: $unsigned));
        $schema->tables[$tableName] = $table;

        SchemaStateProvider::setSchema($schema);
    }

    private function getKeyReturnType(string $modelFqcn): ?Union
    {
        $result = (new \ReflectionMethod(ModelMethodHandler::class, 'getKeyReturnType'))->invoke(null, $modelFqcn);
        $this->assertTrue($result === null || $result instanceof Union);

        return $result;
    }

    private function makeCodebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->classLikeStorageProvider;
        $codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();

        // $progress is declared protected(set) readonly in Psalm 7 — bypass via reflection.
        $progressProperty = new \ReflectionProperty(Codebase::class, 'progress');
        $progressProperty->setValue($codebase, new CollectingProgress());

        return $codebase;
    }
}
