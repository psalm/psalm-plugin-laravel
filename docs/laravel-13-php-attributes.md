# Laravel 13: PHP Attribute Support for Eloquent Models

Tracking task for Laravel 13 PHP attribute support in psalm-plugin-laravel.

## Background

Laravel 13 introduces PHP attributes as an alternative to property declarations for model configuration. Instead of:

```php
class Post extends Model
{
    protected $table = 'blog_posts';
    protected $fillable = ['title', 'body'];
    protected $hidden = ['secret'];
}
```

Users can write:

```php
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Table('blog_posts')]
#[Fillable('title', 'body')]
#[Hidden('secret')]
class Post extends Model {}
```

## New Attribute Classes (Laravel 13)

### Eloquent Model attributes
- `#[Table('name')]` — overrides `$table` property
- `#[Fillable('col1', 'col2')]` — overrides `$fillable` property
- `#[Guarded('col1')]` — overrides `$guarded` property
- `#[Hidden('col1', 'col2')]` — overrides `$hidden` property
- `#[Visible('col1', 'col2')]` — overrides `$visible` property
- `#[Appends('accessor1')]` — overrides `$appends` property
- `#[Connection('mysql')]` — overrides `$connection` property
- `#[Touches('parent')]` — overrides `$touches` property
- `#[Unguarded]` — sets model as unguarded

### Other attributes (not model-specific)
- `#[Backoff]`, `#[FailOnTimeout]`, `#[MaxExceptions]`, `#[Queue]`, `#[Timeout]`, `#[Tries]`, `#[UniqueFor]` — Queue job configuration
- `#[Signature]`, `#[Description]` — Console command configuration
- `#[RedirectTo]`, `#[StopOnFirstFailure]` — Form request configuration
- `#[Collects]`, `#[PreserveKeys]` — API resource configuration
- `#[UseModel]` — Factory configuration
- `#[Seed]`, `#[Seeder]` — Test seeder configuration

## Impact on psalm-plugin-laravel

### `#[Table]` — Affects SchemaAggregator table resolution

**Priority: High**

`ModelDiscoveryProvider` needs to know which database table a model uses in order to map migration-discovered columns to the correct model. Currently, this is determined by:

1. The `$table` property on the model class
2. Convention (snake_case pluralized class name)

If a model uses `#[Table('custom_table')]` instead of `protected $table = 'custom_table'`, the plugin must read the attribute to resolve the correct table name. Without this, models using `#[Table]` will either:
- Fall back to the convention-based name (wrong table, wrong columns)
- Fail silently with no property types inferred

**Implementation approach:** When resolving a model's table name, check for the `#[Table]` attribute on the class in addition to reading the `$table` property. This can be done via:
- Reflection: `$reflectionClass->getAttributes(Table::class)` — works if the model class is autoloaded
- AST parsing: look for `#[Table(...)]` in the class declaration node — works statically

### `#[Fillable]` / `#[Guarded]` — Low priority for Psalm

**Priority: Low**

These control mass-assignment protection. The plugin does not currently use `$fillable` or `$guarded` for type inference — they don't affect what properties exist or their types. No action needed unless the plugin later adds mass-assignment validation.

### `#[Hidden]` / `#[Visible]` — Low priority

**Priority: Low**

These control JSON serialization visibility. Not used for type inference.

### `#[Appends]` — Low priority

**Priority: Low**

Controls which accessors are included in array/JSON output. Not used for type inference.

### `#[Connection]` — Medium priority

**Priority: Medium**

If the plugin ever supports multi-database schema resolution, the `#[Connection]` attribute would need to be read to determine which database connection (and therefore which migrations) apply to a model. Currently not used.

## Action items

1. **When Laravel 13 releases:** Verify that `Facade::defaultAliases()` doesn't change in ways that break our dynamic alias generation (should be automatic).

2. **`#[Table]` support:** Add attribute reading to the model table name resolution logic. This is the only attribute that directly affects the plugin's type inference pipeline.

3. **Test with L13 projects:** Create a test model using `#[Table]` and verify that column types are correctly inferred (or document the gap if not).

## Related docs

- [Eloquent Model Attribute Discovery](./eloquent-model-attribute-discovery.md) — comprehensive comparison of how existing tools discover model metadata
- [Eloquent Discovery Design](./eloquent-discovery-design.md) — design decisions for the new model discovery system
