<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Models\Post;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\StatementsSource;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/446
 */
#[CoversClass(ModelPropertyHandler::class)]
final class ModelPropertyHandlerTest extends TestCase
{
    private ClassLikeStorageProvider $classLikeStorageProvider;

    #[\Override]
    protected function setUp(): void
    {
        $schema = new SchemaAggregator();
        $table = new SchemaTable();
        $table->setColumn(new SchemaColumn('id', SchemaColumn::TYPE_INT));
        $table->setColumn(new SchemaColumn('title', SchemaColumn::TYPE_STRING));
        $table->setColumn(new SchemaColumn('published_at', SchemaColumn::TYPE_STRING, nullable: true));
        $schema->tables['posts'] = $table;
        SchemaStateProvider::setSchema($schema);

        $this->classLikeStorageProvider = new ClassLikeStorageProvider();
        $this->classLikeStorageProvider->create(Post::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->classLikeStorageProvider->remove(Post::class);
    }

    #[Test]
    public function it_recognizes_column_property_in_read_mode(): void
    {
        $event = $this->createEvent(Post::class, 'title', readMode: true);

        $this->assertTrue(ModelPropertyHandler::doesPropertyExist($event));
    }

    #[Test]
    public function it_returns_null_for_write_mode(): void
    {
        $event = $this->createEvent(Post::class, 'title', readMode: false);

        $this->assertNull(
            ModelPropertyHandler::doesPropertyExist($event),
            'Write mode is handled via pseudo_property_set_types, not doesPropertyExist()',
        );
    }

    #[Test]
    public function it_returns_null_for_unknown_property(): void
    {
        $event = $this->createEvent(Post::class, 'nonexistent', readMode: true);

        $this->assertNull(ModelPropertyHandler::doesPropertyExist($event));
    }

    #[Test]
    public function it_resolves_all_columns_for_model(): void
    {
        $columns = ModelPropertyHandler::resolveAllColumns(Post::class);

        $this->assertArrayHasKey('id', $columns);
        $this->assertArrayHasKey('title', $columns);
        $this->assertArrayHasKey('published_at', $columns);
        $this->assertCount(3, $columns);
    }

    #[Test]
    public function it_returns_empty_for_unknown_model(): void
    {
        $this->assertSame([], ModelPropertyHandler::resolveAllColumns('NonExistent\\Model'));
    }

    private function createEvent(string $className, string $propertyName, bool $readMode): PropertyExistenceProviderEvent
    {
        $codebase = (new \ReflectionClass(\Psalm\Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->classLikeStorageProvider;

        $source = $this->createMock(StatementsSource::class);
        $source->method('getCodebase')->willReturn($codebase);

        return new PropertyExistenceProviderEvent(
            $className,
            $propertyName,
            $readMode,
            $source,
        );
    }
}
