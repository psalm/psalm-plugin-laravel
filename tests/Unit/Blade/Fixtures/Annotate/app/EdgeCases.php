<?php

declare(strict_types=1);

namespace AnnotateFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFacade;

/** Producers the annotate pass must leave a view undeclared for, each in a shape it only sees indirectly. */
final class EdgeCases
{
    /** A shape key Blade would pair with a closer further down the template. */
    public function shapeKey(): View
    {
        return view('edge-key', ['meta' => ['@verbatim' => 1]]);
    }

    /** The header docblock of this template ignores its `@var` tags. */
    public function ignored(): View
    {
        return view('edge-ignored', ['title' => 'Ada']);
    }

    public function agreeing(): View
    {
        return view('edge-cond', ['title' => 'Ada']);
    }

    /** The second producer of `edge-cond` sits in a condition, where no statement of its own records it. */
    public function condition(): ?View
    {
        if ($view = view('edge-cond', ['title' => 42])) {
            return $view;
        }

        return null;
    }

    public function firstAgreeing(): View
    {
        return view('edge-first', ['title' => 'Ada']);
    }

    /** Renders `edge-first` or its fallback, whichever exists. */
    public function first(): View
    {
        return ViewFacade::first(['edge-first', 'edge-first-fallback'], ['title' => 42]);
    }

    /** `title` is written by the view's composer; `extra` is not. */
    public function composed(): View
    {
        return view('edge-composed', ['title' => 42, 'extra' => 'Ada']);
    }

    /** The only call site that types `edge-parse` is fine; the one in the file that does not parse is what blocks it. */
    public function parsed(): View
    {
        return view('edge-parse', ['title' => 'Ada']);
    }
}
