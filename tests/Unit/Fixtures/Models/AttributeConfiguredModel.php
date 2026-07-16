<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Model;

/**
 * Twin of {@see ScalarFieldsModel} that drives the same lists through Laravel 13.0+ PHP class
 * attributes instead of `$properties`, exercising
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelInstancePreparer}::prepare().
 *
 * The baseline `$property` declarations on the union lists (`$hidden` / `$visible` / `$appends` /
 * `$fillable`) prove the attribute columns are MERGED in, not replaced; `$guarded` is left at the base
 * default `['*']` so `#[Guarded]` can replace it.
 *
 * The `#[*]` classes only exist from Laravel 13.0, so the consuming test is gated on
 * `class_exists()`; below that floor this file is never loaded.
 *
 * Do NOT add a `timestamps:` argument to the `#[Table]` below: its absence is the point of the
 * timestamps case that reuses this model ({@see TableTimestampsEnabledModel} covers the present-argument
 * side). Adding one fails that case as a Laravel drift, which would misdiagnose it.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Hidden('attr_hidden')]
#[Visible('attr_visible')]
#[Appends('attr_append')]
#[Fillable('attr_fillable')]
#[Guarded('attr_guarded')]
#[Connection('attr_connection')]
#[Table('attr_table')]
final class AttributeConfiguredModel extends Model
{
    /** @var list<string> */
    protected $hidden = ['prop_hidden'];

    /** @var list<string> */
    protected $visible = ['prop_visible'];

    /** @var list<string> */
    protected $appends = ['prop_append'];

    /** @var list<string> */
    protected $fillable = ['prop_fillable'];
}
