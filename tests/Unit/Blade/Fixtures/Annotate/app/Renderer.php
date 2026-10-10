<?php

declare(strict_types=1);

namespace AnnotateFixture;

use Illuminate\Contracts\View\View;

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

    public function loop(): View
    {
        return view('loop', ['items' => $this->items()]);
    }

    /** An unresolvable producer: nothing proves what 'locals' is passed, so every name it reads is a candidate. */
    public function locals(): string
    {
        return view('locals', ['rows' => $this->items()])->render();
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
