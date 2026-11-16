<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;

/** An unbounded template is unknown, but an intersection with a class pins part of the value. */
final class MixedBoundIntersection
{
    /**
     * @template T
     *
     * @param T&Tag $value
     */
    public function unrelatedClass(mixed $value): View
    {
        return view('member', ['member' => $value]);
    }

    /**
     * @template T
     *
     * @param T&Member $value
     */
    public function declaredClass(mixed $value): View
    {
        return view('member', ['member' => $value]);
    }

    /**
     * @template T
     *
     * @param T $value
     */
    public function unconstrained(mixed $value): View
    {
        return view('member', ['member' => $value]);
    }
}
