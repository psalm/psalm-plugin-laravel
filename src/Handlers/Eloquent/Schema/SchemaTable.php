<?php

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

class SchemaTable
{
    /** @var array<string, SchemaColumn> */
    public $columns = [];

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

    public function dropColumn(string $column_name): void
    {
        unset($this->columns[$column_name]);
    }
}
