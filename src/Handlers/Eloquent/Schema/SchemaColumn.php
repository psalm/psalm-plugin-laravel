<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

/** @psalm-suppress PossiblyUnusedProperty $default and $unsigned will be used for model attribute type inference */
final class SchemaColumn
{
    public const TYPE_STRING = 'string';

    public const TYPE_INT = 'int';

    public const TYPE_FLOAT = 'float';

    public const TYPE_BOOL = 'bool';

    public const TYPE_ENUM = 'enum';

    public const TYPE_ARRAY = 'array';

    public const TYPE_MIXED = 'mixed';

    /**
     * Allowed values for enum columns, e.g. ['draft', 'published'] from
     * {@see \Illuminate\Database\Schema\Blueprint::enum()}'s second argument.
     * Empty for non-enum column types.
     *
     * @var array<int, string>
     */
    public array $options;

    /**
     * @param array<int, string> $options Allowed enum values (see {@see $options})
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = false,
        array $options = [],
        public ?SchemaColumnDefault $default = null,
        public bool $unsigned = false,
    ) {
        $this->options = $options;
    }
}
