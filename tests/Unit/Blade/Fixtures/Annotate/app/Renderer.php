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

    public function loop(): View
    {
        return view('loop', ['items' => $this->items()]);
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
