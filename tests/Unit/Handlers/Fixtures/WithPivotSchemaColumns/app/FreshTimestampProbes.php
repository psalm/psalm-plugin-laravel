<?php

declare(strict_types=1);

namespace WithPivotFixture;

use Illuminate\Database\Eloquent\Model;
use WithPivotFixture\Models\Team;

/** Calls the inherited `HasTimestamps::freshTimestamp()` on `$this`. */
class StampedProbe extends Model
{
    public function stamp(): void
    {
        $_fresh_this = $this->freshTimestamp();
        /** @psalm-trace $_fresh_this */
    }
}

/** Overrides the inherited method: its own declared type must survive. */
class OverridingProbe extends Model
{
    #[\Override]
    public function freshTimestamp(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

/** Inherits the override from its parent. */
class InheritingOverrideProbe extends OverridingProbe {}

/** A trait overriding the method: the using model declares it, so the trait's type wins. */
trait OverridingStampTrait
{
    public function freshTimestamp(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

class TraitOverridingProbe extends Model
{
    use OverridingStampTrait;
}

/** Not a model: its own `freshTimestamp()` is none of the handler's business. */
final class ClockProbe
{
    public function freshTimestamp(): int
    {
        return 0;
    }
}

function fresh_timestamp_probes(
    Team $team,
    Model $base,
    OverridingProbe $overriding,
    InheritingOverrideProbe $inheriting,
    TraitOverridingProbe $trait_overriding,
    ClockProbe $clock,
): void {
    $_fresh_team = $team->freshTimestamp();
    /** @psalm-trace $_fresh_team */

    $_fresh_base = $base->freshTimestamp();
    /** @psalm-trace $_fresh_base */

    $_fresh_override = $overriding->freshTimestamp();
    /** @psalm-trace $_fresh_override */

    $_fresh_inherited_override = $inheriting->freshTimestamp();
    /** @psalm-trace $_fresh_inherited_override */

    $_fresh_trait_override = $trait_overriding->freshTimestamp();
    /** @psalm-trace $_fresh_trait_override */

    $_fresh_plain = $clock->freshTimestamp();
    /** @psalm-trace $_fresh_plain */
}
