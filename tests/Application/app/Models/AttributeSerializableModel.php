<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Serialization archetype driven by Laravel 13.0+ PHP class attributes (`#[Appends]` / `#[Hidden]`)
 * instead of `$properties`. The harness runs no migrations, so the shape comes entirely from the
 * appended, accessor-backed attributes — letting AttributeConfigShapeTest.phpt assert end-to-end that
 * `replayInitializers()` feeds the serialized shape (and that `#[Hidden]` drops an appended key).
 *
 * Modern protected `Attribute` accessors keep the legacy public-accessor lint (`PublicModelAccessor`)
 * quiet. The `#[*]` classes only exist from Laravel 13.0, so the phpt is SKIPIF-gated below that line.
 */
#[Appends('full_name', 'secret_token')]
#[Hidden('secret_token')]
class AttributeSerializableModel extends Model
{
    /**
     * Backs the `full_name` append; appears in the shape.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn(): string => 'computed');
    }

    /**
     * Backs the `secret_token` append; dropped from the shape by `#[Hidden]`.
     *
     * @return Attribute<string, never>
     */
    protected function secretToken(): Attribute
    {
        return Attribute::get(fn(): string => 'shh');
    }
}
