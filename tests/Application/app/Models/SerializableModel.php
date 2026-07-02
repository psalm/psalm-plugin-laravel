<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Serialization archetype: appended, accessor-backed attributes with no migration schema. The harness
 * runs no migrations, so the shape comes entirely from `$appends` (always serialized) — letting
 * ToArrayShapeTest.phpt assert a real, non-`mixed` shape end-to-end. Each append (see its method
 * docblock) exercises one HasAttributes::mutateAttributeForArray() rule; the unit test instead drives
 * column shapes from a hand-built WorkOrder metadata.
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
