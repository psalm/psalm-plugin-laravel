<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

final class SchemaColumn
{
    public const TYPE_STRING = 'string';

    public const TYPE_INT = 'int';

    public const TYPE_FLOAT = 'float';

    public const TYPE_BOOL = 'bool';

    public const TYPE_ENUM = 'enum';

    public const TYPE_MIXED = 'mixed';

    public string $name;

    public string $type;

    public bool $nullable;

    /** @var array<int, string> */
    public array $options = [];

    /**
     * @param array<int, string>|null $options
     */
    public function __construct(
        string $name,
        string $type,
        bool $nullable = false,
        ?array $options = []
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->nullable = $nullable;
        $this->options = $options ?? [];
    }
}
