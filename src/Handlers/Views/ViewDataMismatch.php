<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

/**
 * Why a passed view value does not satisfy the declared type, split the way Psalm's argument
 * analysis splits `InvalidArgument` from `PossiblyNullArgument`, `PossiblyFalseArgument`,
 * `ArgumentTypeCoercion` and `PossiblyInvalidArgument`.
 *
 * @internal
 */
enum ViewDataMismatch
{
    /** No part of the value can satisfy the declaration. */
    case Incompatible;

    /** Only the `null` part does not fit. */
    case PossiblyNull;

    /** Only the `false` part does not fit. */
    case PossiblyFalse;

    /** A parent type of the declared one, or a generic with less specific parameters. */
    case Wider;

    /** Some member of a union fits and another does not. */
    case PartlyDifferent;

    /** @psalm-mutation-free */
    public function isHard(): bool
    {
        return $this === self::Incompatible;
    }

    /** @psalm-mutation-free */
    public function describe(): string
    {
        return match ($this) {
            self::Incompatible => '',
            self::PossiblyNull => ', which may be null',
            self::PossiblyFalse => ', which may be false',
            self::Wider => ', which is wider than the declared type',
            self::PartlyDifferent => ', which may be a different type',
        };
    }
}
