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

    /** `themed-host` renders `themed` through a custom include directive that may pass another type for `text`. */
    public function themed(): View
    {
        return view('themed', ['text' => 'Ada']);
    }

    /** `themed-host` renders `themed-last` through a renderer that takes the view name after the scope. */
    public function themedLast(): View
    {
        return view('themed-last', ['text' => 'Ada']);
    }

    /** `quiet` is named by a custom directive that passes no scope, so this is its only producer. */
    public function quiet(): View
    {
        return view('quiet', ['text' => 'Ada']);
    }

    /** `title` is written by the view's composer; `extra` is not. */
    public function composed(): View
    {
        return view('edge-composed', ['title' => 42, 'extra' => 'Ada']);
    }
}
