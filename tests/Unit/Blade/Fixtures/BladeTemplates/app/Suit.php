<?php

declare(strict_types=1);

namespace Fx;

/**
 * Registered as the `Suit` AliasLoader alias in bootstrap/app.php. An enum cannot be extended, so the
 * plugin's alias stub must model the alias with `class_alias()` for `Suit::Hearts` to resolve.
 */
enum Suit: string
{
    public const LABEL = 'suit';

    case Hearts = 'h';
    case Spades = 's';
}
