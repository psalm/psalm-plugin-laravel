<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use Illuminate\Database\Schema\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class MorphKeyTypeTest extends AbstractSchemaAggregatorTestCase
{
    private const MIGRATION = <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration {
            public function up(): void
            {
                Schema::create('comments', function (Blueprint $table) {
                    $table->morphs('taggable');
                    $table->nullableMorphs('imageable');
                    $table->numericMorphs('numeric');
                    $table->nullableNumericMorphs('nullable_numeric');
                });
            }
        };
        PHP;

    private string $originalMorphKeyType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalMorphKeyType = Builder::$defaultMorphKeyType;
    }

    protected function tearDown(): void
    {
        Builder::$defaultMorphKeyType = $this->originalMorphKeyType;

        parent::tearDown();
    }

    #[Test]
    public function morphs_default_to_unsigned_int_key(): void
    {
        Builder::defaultMorphKeyType('int');

        $table = $this->schemaFromMigration(self::MIGRATION)->tables['comments'];

        self::assertTableHasNotNullableColumnOfType('taggable_id', 'int', $table);
        self::assertTableHasNullableColumnOfType('imageable_id', 'int', $table);
        $this->assertTrue($table->columns['taggable_id']->unsigned);
    }

    /** @param 'uuid'|'ulid' $keyType */
    #[Test]
    #[DataProvider('nonIntKeyTypes')]
    public function morphs_follow_the_configured_uuid_or_ulid_key_type(string $keyType): void
    {
        Builder::defaultMorphKeyType($keyType);

        $table = $this->schemaFromMigration(self::MIGRATION)->tables['comments'];

        self::assertTableHasNotNullableColumnOfType('taggable_type', 'string', $table);
        self::assertTableHasNotNullableColumnOfType('taggable_id', 'string', $table);
        self::assertTableHasNullableColumnOfType('imageable_type', 'string', $table);
        self::assertTableHasNullableColumnOfType('imageable_id', 'string', $table);
    }

    /** @param 'int'|'uuid'|'ulid' $keyType */
    #[Test]
    #[DataProvider('allKeyTypes')]
    public function numeric_morphs_stay_int_regardless_of_the_configured_key_type(string $keyType): void
    {
        Builder::defaultMorphKeyType($keyType);

        $table = $this->schemaFromMigration(self::MIGRATION)->tables['comments'];

        self::assertTableHasNotNullableColumnOfType('numeric_id', 'int', $table);
        self::assertTableHasNullableColumnOfType('nullable_numeric_id', 'int', $table);
    }

    /** @return iterable<string, array{string}> */
    public static function nonIntKeyTypes(): iterable
    {
        yield 'uuid' => ['uuid'];
        yield 'ulid' => ['ulid'];
    }

    /** @return iterable<string, array{string}> */
    public static function allKeyTypes(): iterable
    {
        yield 'int' => ['int'];
        yield from self::nonIntKeyTypes();
    }
}
