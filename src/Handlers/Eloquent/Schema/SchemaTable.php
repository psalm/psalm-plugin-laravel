<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

final class SchemaTable
{
    /** @var array<string, SchemaColumn> */
    public array $columns = [];

    /** @psalm-external-mutation-free */
    public function setColumn(SchemaColumn $column): void
    {
        $this->columns[$column->name] = $column;
    }

    public function renameColumn(string $old_name, string $new_name): void
    {
        if (!isset($this->columns[$old_name])) {
            return;
        }

        $old_column = $this->columns[$old_name];

        unset($this->columns[$old_name]);

        $old_column->name = $new_name;

        $this->columns[$new_name] = $old_column;
    }

    /** @psalm-external-mutation-free */
    public function dropColumn(string $column_name): void
    {
        unset($this->columns[$column_name]);
    }
}
