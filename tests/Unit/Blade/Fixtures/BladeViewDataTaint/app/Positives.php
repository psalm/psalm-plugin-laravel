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

    /** The view is a class component's own, so its `@props` variable is seeded after the prelude. */
    public function seededView(Request $request): View
    {
        return view('components.banner', ['label' => $request->input('q')]);
    }

    /** Each link writes only its own argument: `a` is tainted, `b` is a literal. */
    public function twoLinks(Request $request): View
    {
        return view('both', ['a' => $request->input('q')])->with('b', 'fixed');
    }

    public function mergeDataNamed(Factory $views, Request $request): View
    {
        return $views->make('merge-named', mergeData: ['q' => $request->input('q')]);
    }

    public function mergeDataHelper(Request $request): View
    {
        return view('merge-helper', [], ['q' => $request->input('q')]);
    }

    public function mergeDataHelperNamed(Request $request): View
    {
        return view('merge-helper-named', mergeData: ['q' => $request->input('q')]);
    }

    public function mergeDataFacade(Request $request): View
    {
        return ViewFacade::make('merge-facade', [], ['q' => $request->input('q')]);
    }

    /** Laravel's finder trims the name, so this renders `padded`. */
    public function paddedName(Request $request): View
    {
        return view(' padded ', ['q' => $request->input('q')]);
    }

    public function plainSubclass(PlainFactory $views, Request $request): View
    {
        return $views->make('subclass-plain', ['q' => $request->input('q')]);
    }

    /** `$data` wins a shared key, so the tainted `$data` value is the one the template gets. */
    public function dataBeatsMergeData(Request $request): View
    {
        return view('data-over-merge', ['q' => $request->input('q')], ['q' => 'fixed']);
    }

    /**
     * An open spread may carry any key, so it cannot prove the earlier write was overwritten.
     *
     * @param array<string, mixed> $extra
     */
    public function openSpreadAfterKey(Request $request, array $extra): View
    {
        return view('spread-open', ['q' => $request->input('q'), ...$extra]);
    }

    public function literalSpreadTaintLast(Request $request): View
    {
        return view('spread-literal-taint', ['q' => 'fixed', ...['q' => $request->input('q')]]);
    }

    /**
     * Over-report, accepted: `with()` overwrites `q` at run time, but each link of a chain writes
     * its own arguments and the inner link cannot see the outer one.
     */
    public function overwrittenByLaterWith(Request $request): View
    {
        return view('overwritten-chain', ['q' => $request->input('q')])->with('q', 'fixed');
    }
}
