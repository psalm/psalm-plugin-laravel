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

    /** An unresolvable producer: nothing proves what 'locals' is passed, so every name it reads is a candidate. */
    public function locals(): string
    {
        return view('locals', ['rows' => $this->items()])->render();
    }

    /** `diff()` is declared `static`, and a count difference infers the unbounded `int<min, max>`. */
    public function statics(): View
    {
        $all = new Collection($this->items());

        return view('statics', [
            'missing' => $all->diff(['b']),
            'byKey' => $all->groupBy(static fn(string $item): string => $item)->all(),
            'stats' => ['total' => \count($this->items()) - \count($all)],
        ]);
    }

    /** @return list<string> */
    private function items(): array
    {
        return ['a', 'b'];
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
