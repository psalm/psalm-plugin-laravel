<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Serialization archetype: appended, accessor-backed attributes with no migration schema.
 *
 * The type-test harness runs no migrations, so this model has an empty column schema. Its serialized
 * shape therefore comes entirely from `$appends`, which Laravel always serializes — letting
 * ToArrayShapeTest.phpt assert a real, non-`mixed` shape end-to-end through Psalm. The appends cover
 * the accessor styles and the serialization rules in HasAttributes::mutateAttributeForArray():
 *  - `full_name`:     legacy `getFullNameAttribute(): string` — a scalar, kept as `string`.
 *  - `badge_number`:  modern `Attribute::get(fn(): int)` — a scalar, kept as `int`.
 *  - `secret_token`:  appended but also in `$hidden`, so it is dropped from the shape.
 *  - `roles`:         legacy accessor declared `@return Collection<int, string>` — a generic
 *                     collection serializes to `array<int, string>` (keys kept, scalar values kept).
 *  - `tags`:          modern `Attribute` returning a bare `Collection` — no declared value type, so
 *                     the inner shape is unknown and it serializes to `array<array-key, mixed>`.
 *  - `permissions`:   modern `Attribute<Collection<int, Collection<int, string>>>` — an `Arrayable`
 *                     element collapses one level, so `array<int, array<array-key, mixed>>`.
 *  - `published_at`:  modern date accessor — a modern `Attribute` converts a `DateTimeInterface` to
 *                     an ISO string, so it serializes to `string`.
 *  - `registered_at`: legacy date accessor — a legacy `getXxxAttribute()` does NOT convert dates, so
 *                     it keeps its read type (`CarbonInterface`); proves the date mapping is gated on
 *                     the modern accessor style.
 *
 * Consumed only by ToArrayShapeTest.phpt; the unit test drives column shapes from a hand-built
 * WorkOrder metadata instead, so this model carries the appends-only end-to-end case.
 */
class SerializableModel extends Model
{
    /** @var list<string> */
    protected $appends = ['full_name', 'badge_number', 'secret_token', 'roles', 'tags', 'permissions', 'published_at', 'registered_at'];

    /** @var list<string> */
    protected $hidden = ['secret_token'];

    /** Legacy scalar accessor backing the `full_name` append. */
    public function getFullNameAttribute(): string
    {
        return 'computed';
    }

    /** Legacy accessor backing the `secret_token` append; dropped from the shape by `$hidden`. */
    public function getSecretTokenAttribute(): string
    {
        return 'shh';
    }

    /**
     * Legacy accessor returning a typed collection; serializes to `array<int, string>` (keys kept).
     *
     * @return Collection<int, string>
     */
    public function getRolesAttribute(): Collection
    {
        return new Collection(['admin']);
    }

    /** Legacy date accessor; legacy accessors are not date-serialized, so the read type is kept. */
    public function getRegisteredAtAttribute(): CarbonInterface
    {
        return now();
    }

    /**
     * Modern scalar Attribute accessor backing the `badge_number` append.
     *
     * @return Attribute<int, never>
     */
    protected function badgeNumber(): Attribute
    {
        return Attribute::get(fn(): int => 42);
    }

    /**
     * Modern Attribute returning a bare collection (no declared value type); serializes to
     * `array<array-key, mixed>`.
     *
     * @return Attribute<Collection, never>
     */
    protected function tags(): Attribute
    {
        return Attribute::get(fn(): Collection => new Collection(['x']));
    }

    /**
     * Modern Attribute returning a nested typed collection; the inner `Collection` element is an
     * `Arrayable`, so it collapses one level to `array<int, array<array-key, mixed>>`.
     *
     * @return Attribute<Collection<int, Collection<int, string>>, never>
     */
    protected function permissions(): Attribute
    {
        return Attribute::get(fn(): Collection => new Collection([new Collection(['read'])]));
    }

    /**
     * Modern date Attribute accessor; a modern accessor serializes a DateTimeInterface to an ISO
     * string, so the shape is `string`.
     *
     * @return Attribute<CarbonInterface, never>
     */
    protected function publishedAt(): Attribute
    {
        return Attribute::get(fn(): CarbonInterface => now());
    }
}
