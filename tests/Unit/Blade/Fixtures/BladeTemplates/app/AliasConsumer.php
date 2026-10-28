<?php

declare(strict_types=1);

namespace Fx;

/** Reaches every aliased class only through its global AliasLoader alias, as a PHP file without a `use` statement does. */
#[\AttrAlias(1)]
final class AliasConsumer
{
    public function value(): string
    {
        $case = \Suit::Hearts;
        /** @psalm-check-type-exact $case = \Fx\Suit::Hearts */

        return $case->value;
    }

    public function chained(): string
    {
        // `SuitAgain` is an alias of the `Suit` alias, so Psalm has to follow two hops.
        $case = \SuitAgain::Hearts;
        /** @psalm-check-type-exact $case = \Fx\Suit::Hearts */

        return $case->value;
    }

    public function interfaceConstant(): string
    {
        return \IfaceAlias::X;
    }

    public function aliasedParam(\Suit $case): string
    {
        return $case->name;
    }

    public function pass(): string
    {
        return $this->aliasedParam(Suit::Spades);
    }

    /** @param \ClsAlias::A|\ClsAlias::B $v */
    public function constrained(string $v): string
    {
        return $v;
    }

    public function withinUnion(): string
    {
        return $this->constrained('a');
    }

    // The one deliberate error: it can only be reported if the docblock constants resolved through the alias.
    public function outsideUnion(): string
    {
        return $this->constrained('c');
    }
}
