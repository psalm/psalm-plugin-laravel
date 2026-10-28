<?php

declare(strict_types=1);

namespace Fx;

/** Aliased as `IfaceAlias`. An interface is not a class, so Psalm's alias lookup used to miss its storage (#2128). */
interface HasLabel
{
    public const X = 'x';
}

/** Aliased as `AttrAlias`. Psalm reads attribute class storage by the raw name written in `#[...]`. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Tag
{
    public function __construct(public readonly int $weight) {}
}

/** Aliased as `ClsAlias`. Its constants feed a docblock union, which Psalm also resolves by the raw alias name. */
final class Level
{
    public const A = 'a';
    public const B = 'b';
}
