<?php
namespace Psalm\LaravelPlugin;

class SchemaColumn
{
    /** @var string */
    public $name;

    /** @var string */
    public $type;

    /** @var bool */
    public $nullable;

    /** @var ?array<int, string> */
    public $options;

    public function __construct(
        string $name,
        string $type,
        bool $nullable = false,
        ?array $options = null
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->nullable = $nullable;
        $this->options = $options;
    }
}
