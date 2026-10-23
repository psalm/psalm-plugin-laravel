<?php

declare(strict_types=1);

namespace AnnotateFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

final class Renderer
{
    public function home(): View
    {
        return view('home', ['title' => $this->title(), 'post' => $this->post()]);
    }

    public function declared(): View
    {
        return view('declared', ['title' => $this->title(), 'extra' => $this->title()]);
    }

    public function agreeing(): View
    {
        return view('conflict', ['flag' => $this->title()]);
    }

    public function disagreeing(): View
    {
        return view('conflict', ['flag' => $this->post()]);
    }

    public function headed(): View
    {
        return view('headed', ['title' => $this->title()]);
    }

    public function strict(): View
    {
        return view('strict', ['title' => $this->title()]);
    }

    public function noteGiven(): View
    {
        return view('note', ['note' => $this->title()]);
    }

    public function noteOmitted(): View
    {
        return view('note', ['other' => 1]);
    }

    public function loop(): View
    {
        return view('loop', ['items' => $this->items()]);
    }

    /** Collection methods returning `static`: the inferred types carry Psalm's late-static marker. */
    public function lateStatic(): View
    {
        $missing = collect(['a', 'b'])->diff(['b']);
        $byRole = collect(['a', 'b'])->groupBy(static fn(string $name): int => \strlen($name))->all();

        return view('collections', ['missing' => $missing, 'byRole' => $byRole, 'kind' => \get_class($missing)]);
    }

    /** The same names as plain `Collection`s, so the two producers differ only by `&static`. */
    public function plain(): View
    {
        /** @var Collection<int<0, 1>, string> $missing */
        $missing = new Collection(['a', 'b']);
        /** @var array<int<1, max>, Collection<int, string>> $byRole */
        $byRole = [];

        return view('collections', ['missing' => $missing, 'byRole' => $byRole, 'kind' => \get_class($missing)]);
    }

    /** An unresolvable producer: nothing proves what 'locals' is passed, so every name it reads is a candidate. */
    public function locals(): string
    {
        return view('locals', ['rows' => $this->items()])->render();
    }

    /** A count difference infers the unbounded `int<min, max>`. */
    public function statics(): View
    {
        return view('statics', ['stats' => ['total' => \count($this->items()) - \count($this->items())]]);
    }

    public function outerGiven(): View
    {
        return view('outer', ['showCount' => true]);
    }

    public function outerOmitted(): View
    {
        return view('outer', []);
    }

    public function inner(): View
    {
        return view('inner', ['showCount' => true]);
    }

    public function counter(): View
    {
        return view('counter', ['showCount' => $this->flag()]);
    }

    /** @return list<string> */
    private function items(): array
    {
        return ['a', 'b'];
    }

    private function flag(): bool
    {
        return \random_int(0, 1) === 1;
    }

    private function title(): string
    {
        return 'Ada';
    }

    private function post(): Post
    {
        return new Post();
    }
}
