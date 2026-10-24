<?php

declare(strict_types=1);

namespace BladeViewDataTaintFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\Factory;

/**
 * Every shape the bridge carries: the value is request input, the template echoes it unescaped.
 *
 * @psalm-suppress UnusedClass
 */
final class Positives
{
    public function plain(Request $request): View
    {
        return view('search', ['q' => $request->input('q')]);
    }

    public function typed(Request $request): View
    {
        return view('typed', ['q' => $request->input('q')]);
    }

    public function chained(Request $request): View
    {
        return view('chained')->with('q', $request->input('q'));
    }

    public function chainedArray(Request $request): View
    {
        return view('chained-array')->with(['q' => $request->input('q')]);
    }

    public function facade(Request $request): View
    {
        return ViewFacade::make('facade', ['q' => $request->input('q')]);
    }

    public function factory(Factory $views, Request $request): View
    {
        return $views->make('factory', ['q' => $request->input('q')]);
    }

    public function response(Request $request): Response
    {
        return response()->view('response', ['q' => $request->input('q')]);
    }

    public function nested(Request $request): Response
    {
        return response(view('nested', ['q' => $request->input('q')]));
    }

    public function slashName(Request $request): View
    {
        return view('admin/panel', ['q' => $request->input('q')]);
    }

    public function namespaced(Request $request): View
    {
        return view('pkg::widget', ['q' => $request->input('q')]);
    }

    /** Each link writes only its own argument: `a` is tainted, `b` is a literal. */
    public function twoLinks(Request $request): View
    {
        return view('both', ['a' => $request->input('q')])->with('b', 'fixed');
    }
}
