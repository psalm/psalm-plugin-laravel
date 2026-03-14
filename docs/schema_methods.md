# SchemaAggregator: Supported Methods & Improvement Plan

This document tracks which `Schema` facade and `Blueprint` methods the `SchemaAggregator` supports for migration parsing.

---

## A. Schema Facade (Builder-level) Methods

| Method                                         | Status      | Priority | Notes                                                                                                                                                         |
|------------------------------------------------|-------------|----------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Schema::create($table, $cb)`                  | Supported   | -        |                                                                                                                                                               |
| `Schema::table($table, $cb)`                   | Supported   | -        |                                                                                                                                                               |
| `Schema::drop($table)`                         | Supported   | -        |                                                                                                                                                               |
| `Schema::dropIfExists($table)`                 | Supported   | -        |                                                                                                                                                               |
| `Schema::rename($from, $to)`                   | Supported   | -        |                                                                                                                                                               |
| `Schema::dropColumns($table, $columns)`        | Supported   | -        | Added in #448                                                                                                                                                 |
| `Schema::connection($name)->create/table(...)` | **Missing** | High     | Larastan handles this. Common in multi-DB projects. The static call is chained through `connection()` returning a Builder, then calling `create`/`table`/etc. |
| `Schema::dropAllTables()`                      | N/A         | -        | Runtime-only, not statically analyzable                                                                                                                       |

## B. Blueprint Column Type Methods

| Method                                                                                  | Status      | Priority | Notes                                                                                       |
|-----------------------------------------------------------------------------------------|-------------|----------|---------------------------------------------------------------------------------------------|
| Integer types (`id`, `increments`, `*Integer`, `unsigned*`, `foreignId`)                | Supported   | -        |                                                                                             |
| String types (`string`, `char`, `text`, `*Text`, `json`, `jsonb`, `uuid`, `ulid`, etc.) | Supported   | -        |                                                                                             |
| `boolean`                                                                               | Supported   | -        |                                                                                             |
| `float`, `double`, `decimal`, `unsigned*`                                               | Supported   | -        |                                                                                             |
| `enum`, `set`                                                                           | Supported   | -        |                                                                                             |
| Date/time types (`date`, `dateTime`, `time`, `timestamp` + Tz variants)                 | Supported   | -        |                                                                                             |
| `binary`                                                                                | Supported   | -        |                                                                                             |
| `softDeletes` / `softDeletesTz`                                                         | Supported   | -        |                                                                                             |
| Geometry types (`geometry`, `point`, `polygon`, etc.)                                   | Supported   | -        |                                                                                             |
| `foreignIdFor` (string + class reference)                                               | Supported   | -        |                                                                                             |
| `tinyText`                                                                              | Supported   | -        | Mapped to `string`                                                                          |
| `softDeletesDatetime`                                                                   | Supported   | -        | Mapped to `string`, nullable                                                                |
| `vector`                                                                                | Supported   | -        | Mapped to `array` (`array<int, float>`)                                                     |
| `tsvector`                                                                              | Supported   | -        | PostgreSQL full-text search. Mapped to `string`                                             |
| `geography`                                                                             | Supported   | -        | Similar to `geometry`. Mapped to `mixed`                                                    |
| `rawColumn`                                                                             | Supported   | -        | Mapped to `mixed`                                                                           |

## C. Blueprint Structural/Drop Methods

| Method                                             | Status      | Priority | Notes                                                                                                     |
|----------------------------------------------------|-------------|----------|-----------------------------------------------------------------------------------------------------------|
| `dropColumn($col)` (string)                        | Supported   | -        |                                                                                                           |
| `dropColumn(['col1','col2'])` (array)              | Supported   | -        | Fixed in #448                                                                                             |
| `dropTimestamps` / `dropTimestampsTz`              | Supported   | -        |                                                                                                           |
| `dropRememberToken`                                | Supported   | -        |                                                                                                           |
| `dropSoftDeletes` / `dropSoftDeletesTz`            | Supported   | -        |                                                                                                           |
| `dropMorphs`                                       | Supported   | -        |                                                                                                           |
| `dropIfExists`                                     | Supported   | -        |                                                                                                           |
| `timestamps` / `nullableTimestamps`                | Supported   | -        |                                                                                                           |
| `rememberToken`                                    | Supported   | -        |                                                                                                           |
| `morphs` / `nullableMorphs` / `numericMorphs`      | Supported   | -        |                                                                                                           |
| `uuidMorphs` / `nullableUuidMorphs` / `ulidMorphs` | Supported   | -        |                                                                                                           |
| `renameColumn`                                     | Supported   | -        |                                                                                                           |
| `after($col, Closure)`                             | Supported   | -        | Recursively processes nested closure to find column defs inside.                                          |
| `$table->rename('new_name')` (method form)         | Supported   | -        | Renames the table entry (distinct from `renameColumn` which renames a column).                            |
| `addColumn`                                        | Supported   | -        | Remaps `addColumn('type', 'name')` to the resolved type method and re-dispatches through the same switch. |
| `dropConstrainedForeignId($col)`                   | Supported   | -        | Drops column (foreign key constraint is runtime-only).                                                    |
| `dropForeignIdFor($model)`                         | **Missing** | Low      | Drops column based on model class.                                                                        |
| `dropConstrainedForeignIdFor($model)`              | **Missing** | Low      | Same as above with constraint.                                                                            |

## D. Migration Scanning Improvements

| Feature                                    | Status      | Priority | Notes                                                                                                                                                                                                                                                     |
|--------------------------------------------|-------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `up()` method scanning                     | Supported   | -        |                                                                                                                                                                                                                                                           |
| `down()` correctly ignored                 | Supported   | -        |                                                                                                                                                                                                                                                           |
| Non-`up()` method scanning                 | **Missing** | Medium   | Larastan scans all class methods except `down()`. If `up()` calls `$this->createUsersTable()`, we miss those columns.                                                                                                                                     |
| `if` block flattening                      | **Missing** | High     | Larastan uses `NodeFinder` to extract `Expression` nodes from within `if` blocks inside the closure. We `continue` past non-expression statements but don't drill into conditional blocks. Columns inside `if (app()->isProduction())` blocks are missed. |
| Table name from class constants            | **Missing** | Medium   | `Schema::create(self::TABLE, ...)` or `Schema::create(MyModel::TABLE, ...)` — Larastan resolves constant values. We only handle string literals.                                                                                                          |
| `Schema::connection()->table()` chain      | **Missing** | High     | See section A. Multi-DB migrations use this pattern.                                                                                                                                                                                                      |
| Default `mixed` for unknown column methods | **Missing** | Medium   | Larastan defaults unknown Blueprint methods to `mixed` type. We skip them entirely, losing the column. A project using a custom Blueprint macro would have its columns invisible.                                                                         |

## E. Implementation Plan (by priority)

### High Priority

1. ~~**Fix `addColumn` bug**~~ — Done. Remaps variables before the switch so the resolved type falls through to the correct handler.

2. ~~**`after()` closure support**~~ — Done. Detects closure in second argument and recursively calls `processColumnUpdates()`.

3. **`if` block flattening** — Use `NodeFinder` to extract `Expression` nodes from `If_` blocks within the closure, similar to Larastan's `getUpdateStatements()` (lines 460-483). This ensures columns defined conditionally are still detected.

4. **`Schema::connection()` chain** — Detect `Schema::connection($name)->create/table/drop/...()` pattern in `addUpMethodStatements()`. The outer call is a `MethodCall` whose `var` is a `StaticCall` on `Schema` with method `connection`. Larastan ref: lines 69-76.

### Medium Priority

5. ~~**`softDeletesDatetime`**~~ — Done. Added to switch case alongside `softDeletes`/`softDeletesTz`.

6. ~~**`$table->rename()` method form**~~ — Done. Split `rename` (table rename) from `renameColumn` (column rename) in the switch.

7. **Table name from class constants** — Resolve `SomeClass::CONSTANT` in the first argument of `Schema::create/table()`. Requires evaluating `ClassConstFetch` nodes.

8. **Non-`up()` method scanning** — Change `addClassStatements()` to process all class methods except `down()` (matching Larastan's approach).

9. **Default `mixed` for unknown methods** — In the `default` case of the column type switch, create a `SchemaColumn` with type `mixed` instead of silently skipping.

### Low Priority

10. ~~**New column types**~~ — Done. Added `vector` (array), `tsvector` (string), `geography` (mixed), `rawColumn` (mixed).

11. **`dropConstrainedForeignId` / `dropForeignIdFor` / `dropConstrainedForeignIdFor`** — These drop columns by name or model reference. Low usage in practice.
