<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelInstancePreparer;

/**
 * Resolves the intermediate table of a many-to-many relation from its factory-call arguments,
 * replaying Laravel's own derivation so a `withPivot()` column can be looked up in the migration schema.
 *
 * Instances are built with `newInstanceWithoutConstructor()`: the derivation only needs
 * `getTable()` / `joiningTable()` / `joiningTableSegment()`, and skipping the constructor avoids booting
 * the model (observers, global scopes) during analysis. Any failure (unloadable class, user override
 * that throws) yields null, i.e. "table not provable", never a guess.
 *
 * @internal
 */
final class PivotTableResolver
{
    /**
     * `belongsToMany($related, $table = null)`. `$table` is a literal table name, a Model class-string
     * (mirrors {@see \Illuminate\Database\Eloquent\Relations\BelongsToMany::resolveTableName()}, which
     * takes the model's `getTable()`), or null (the declaring model's `joiningTable()`, which honors an
     * overridden `joiningTableSegment()`). Like Laravel, only null triggers the default: an empty string
     * stays empty and is therefore not a usable table.
     *
     * @param class-string<Model> $declaringModel
     * @param class-string<Model> $relatedModel
     * @return ?non-empty-string
     */
    public static function forBelongsToMany(?string $table, string $declaringModel, string $relatedModel): ?string
    {
        if ($table !== null) {
            return self::explicitTable($table);
        }

        try {
            $name = self::instance($declaringModel)->joiningTable($relatedModel, self::instance($relatedModel));

            return $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `morphToMany($related, $name, $table = null)` / `morphedByMany(...)`. Explicit table as for
     * {@see forBelongsToMany()}; otherwise Laravel's `HasRelationships::morphToMany()` default: the
     * morph name with only its LAST underscore-separated word pluralized (`model_has_role` =>
     * `model_has_roles`). Unlike belongsToMany, Laravel tests falsiness here, so '' also takes the default.
     *
     * @return ?non-empty-string
     */
    public static function forMorphToMany(?string $table, string $morphName): ?string
    {
        if ($table !== null && $table !== '') {
            return self::explicitTable($table);
        }

        try {
            // preg_split() yields at least one word, so the array_pop() below never sees an empty list.
            $words = \preg_split('/(_)/u', $morphName, -1, \PREG_SPLIT_DELIM_CAPTURE);

            if ($words === false) {
                return null;
            }

            $lastWord = \array_pop($words);
            $name = \implode('', $words) . Str::plural($lastWord);

            return $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A table argument naming a Model class resolves to that model's table; anything else is the
     * literal name. Same gate as Laravel: a backslash and a loadable class. The initializer replay
     * stands in for the constructor Laravel runs, so a Laravel 13 `#[Table]` attribute is honored.
     *
     * @return ?non-empty-string null for an empty name or a failed lookup
     */
    private static function explicitTable(string $table): ?string
    {
        if ($table === '') {
            return null;
        }

        if (!\str_contains($table, '\\')) {
            return $table;
        }

        // class_exists()/is_a() assertions widen $table back to string; keep the narrowed literal.
        $literal = $table;

        try {
            if (!\class_exists($table) || !\is_a($table, Model::class, true)) {
                return $literal;
            }

            $reflection = new \ReflectionClass($table);
            $model = $reflection->newInstanceWithoutConstructor();
            ModelInstancePreparer::prepare($reflection, $model);

            $name = $model->getTable();

            return $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param class-string<Model> $class
     */
    private static function instance(string $class): Model
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
