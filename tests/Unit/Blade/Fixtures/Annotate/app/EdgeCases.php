<?php

declare(strict_types=1);

namespace AnnotateFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFacade;

/** Producers the annotate pass must leave a view undeclared for, each in a shape it only sees indirectly. */
final class EdgeCases
{
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
}
