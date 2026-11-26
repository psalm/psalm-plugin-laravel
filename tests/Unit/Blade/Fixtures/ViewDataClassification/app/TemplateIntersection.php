<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;

/** The intersection guarantees a Member, whatever null or false its template bound also allows. */
final class TemplateIntersection
{
    /**
     * @template T of Member|null
     *
     * @param T&Member $member
     */
    public function nullableBound(?Member $member): View
    {
        return view('member', ['member' => $member]);
    }

    /**
     * @template T of Member|false
     *
     * @param T&Member $member
     */
    public function falsableBound(Member|false $member): View
    {
        return view('member', ['member' => $member]);
    }
}
