<?php

declare(strict_types=1);

namespace AnnotateIncludesFixture;

use Illuminate\Contracts\View\View;

final class Renderer
{
    public function chain(): View
    {
        return view('chain-top', ['member' => $this->post()]);
    }

    public function optionalGiven(): View
    {
        return view('opt-top', ['member' => $this->post()]);
    }

    public function optionalOmitted(): View
    {
        return view('opt-top', []);
    }

    public function cycle(): View
    {
        return view('cyc-a', ['x' => $this->post()]);
    }

    public function narrowGiven(): View
    {
        return view('narrow-top', ['member' => $this->post()]);
    }

    public function narrowOmitted(): View
    {
        return view('narrow-top', []);
    }

    public function issetGiven(): View
    {
        return view('isset-top', ['member' => $this->post()]);
    }

    public function issetOmitted(): View
    {
        return view('isset-top', []);
    }

    public function coalesceGiven(): View
    {
        return view('coalesce-top', ['x' => $this->post()]);
    }

    public function coalesceOmitted(): View
    {
        return view('coalesce-top', []);
    }

    /** A typed `member` the template's own loop alias must not be taken for. */
    public function loop(): View
    {
        return view('loop-top', ['member' => $this->post()]);
    }

    public function referenceAlias(): View
    {
        return view('ref-alias-top', ['x' => $this->post()]);
    }

    public function referenceArray(): View
    {
        return view('ref-array-top', ['x' => $this->post()]);
    }

    public function spread(): View
    {
        return view('spread-top', ['member' => $this->post()]);
    }

    public function readsUnknown(): View
    {
        return view('reads-top', ['member' => $this->post()]);
    }

    public function splitString(): View
    {
        return view('split-top', ['member' => 'Ada']);
    }

    public function splitInt(): View
    {
        return view('split-top', ['member' => 1]);
    }

    /** The leaf's own typed producer, next to the include from a parent that plans no type. */
    public function splitLeaf(): View
    {
        return view('split-leaf', ['member' => 'Ada']);
    }

    public function guard(): View
    {
        return view('guard-top', ['x' => \random_int(0, 1) === 1 ? 'text' : null]);
    }

    public function nullCallWithBox(): View
    {
        return view('nullcall-top', ['x' => new Box('text')]);
    }

    public function nullCallWithNull(): View
    {
        return view('nullcall-top', ['x' => null]);
    }

    public function thisOut(): View
    {
        return view('thisout-top', ['x' => new Box('text')]);
    }

    public function assertion(): View
    {
        return view('assert-top', ['x' => \random_int(0, 1) === 1 ? 'text' : null]);
    }

    public function byReference(): View
    {
        return view('byref-top', ['x' => 'text']);
    }

    /** @return ProbeBox<never> */
    private function emptyBox(): ProbeBox
    {
        return new ProbeBox();
    }

    public function generic(): View
    {
        return view('generic-top', ['x' => $this->emptyBox()]);
    }

    public function aliasedExtract(): View
    {
        return view('hydrate-top', ['x' => 'text']);
    }

    public function callbackExtract(): View
    {
        return view('cuf-top', ['x' => 'text']);
    }

    public function dynamicExtract(): View
    {
        return view('dyn-top', ['x' => 'text']);
    }

    public function insideAFunction(): View
    {
        return view('scope-top', ['x' => 'text']);
    }

    public function magicAccessor(): View
    {
        return view('magic-top', ['x' => new Box('text')]);
    }

    private function post(): Post
    {
        return new Post();
    }
}
