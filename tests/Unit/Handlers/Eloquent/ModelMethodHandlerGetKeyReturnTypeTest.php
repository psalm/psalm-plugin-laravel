<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\Progress\VoidProgress;
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

        $method = new \ReflectionMethod(ModelMethodHandler::class, 'getKeyReturnType');
        $result = $method->invoke(null, SectionFailureModel::class, $codebase);

        $this->assertNull($result);
    }

    private function makeCodebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->classLikeStorageProvider;

        // $progress is declared protected(set) readonly in Psalm 7 — bypass via reflection.
        $progressProperty = new \ReflectionProperty(Codebase::class, 'progress');
        $progressProperty->setValue($codebase, new VoidProgress());

        return $codebase;
    }
}
