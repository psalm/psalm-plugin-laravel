<?php

declare(strict_types=1);

namespace Fx;

/** Reaches the enum only through the global `Suit` alias, as a PHP file without a `use` statement does. */
final class EnumAliasConsumer
{
    public function value(): string
    {
        $case = \Suit::Hearts;
        /** @psalm-check-type-exact $case = \Fx\Suit::Hearts */

        return $case->value;
    }

    public function aliasedParam(\Suit $case): string
    {
        return $case->name;
    }

    public function pass(): string
    {
        return $this->aliasedParam(Suit::Spades);
    }
}
