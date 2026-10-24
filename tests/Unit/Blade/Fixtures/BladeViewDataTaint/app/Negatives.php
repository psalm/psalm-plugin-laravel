<?php

declare(strict_types=1);

namespace BladeViewDataTaintFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\View as ViewFacade;

/**
 * Calls that must NOT carry taint into their template.
 *
 * @psalm-suppress UnusedClass
 */
final class Negatives
{
    public function literalValue(): View
    {
        return view('literal', ['q' => 'fixed']);
    }

    public function otherKey(Request $request): View
    {
        return view('other-key', ['t' => $request->input('q')]);
    }

    public function escapedEcho(Request $request): View
    {
        return view('escaped', ['q' => $request->input('q')]);
    }

    public function dynamicName(Request $request, string $name): View
    {
        return view($name, ['q' => $request->input('q')]);
    }

    public function shared(Request $request): View
    {
        ViewFacade::share('q', $request->input('q'));

        return view('shared');
    }

    public function mailable(Request $request): Mailable
    {
        return (new Mailable())->view('mailable', ['q' => $request->input('q')]);
    }

    public function bladeOwnedName(Request $request): View
    {
        return view('owned', ['errors' => $request->input('q')]);
    }

    public function dunderName(Request $request): View
    {
        return view('dunder', ['__q' => $request->input('q')]);
    }

    /** A later key of the same array literal wins at run time. */
    public function overwrittenBySpread(Request $request): View
    {
        return view('overwritten', ['q' => $request->input('q'), ...['q' => 'fixed']]);
    }

    public function overwrittenByData(Request $request): View
    {
        return view('merge-overwritten', ['q' => 'fixed'], ['q' => $request->input('q')]);
    }

    public function overridingFactory(OverridingFactory $views, Request $request): View
    {
        return $views->make('custom-factory', ['q' => $request->input('q')]);
    }

    public function overridingResponses(OverridingResponses $responses, Request $request): Response
    {
        return $responses->view('custom-response', ['q' => $request->input('q')])->with('r', $request->input('q'));
    }
}
