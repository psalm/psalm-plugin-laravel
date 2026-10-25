<?php

declare(strict_types=1);

namespace AnnotateFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Four producers of one view that disagree on most names: the merges `blade:annotate` writes
 * (nullability, sibling classes, `true`/`false`, one class with different generic params) and the
 * ones it lists instead (a fourth class, two scalar kinds).
 */
final class Merger
{
    public function first(): View
    {
        return view('merged', [
            'author' => new Post(),
            'media' => new Post(),
            'trio' => new Post(),
            'flag' => true,
            'posts' => $this->posts(),
            'any' => new Post(),
            'key' => 1,
        ]);
    }

    public function second(): View
    {
        return view('merged', [
            'author' => null,
            'media' => new Video(),
            'trio' => new Video(),
            'flag' => false,
            'posts' => $this->videos(),
            'any' => new Video(),
            'key' => 'k',
        ]);
    }

    public function third(): View
    {
        return view('merged', [
            'author' => $this->maybeAuthor(),
            'media' => new Video(),
            'trio' => new Panel(),
            'flag' => true,
            'posts' => $this->posts(),
            'any' => new Panel(),
            'key' => 1,
        ]);
    }

    public function fourth(): View
    {
        return view('merged', [
            'author' => new Post(),
            'media' => new Post(),
            'trio' => new Panel(),
            'flag' => false,
            'posts' => $this->posts(),
            'any' => $this,
            'key' => 1,
        ]);
    }

    /** The one call site of `merge-control`, whose declaration the end-to-end test contradicts on purpose. */
    public function control(): View
    {
        return view('merge-control', ['flag' => true]);
    }

    public function emptyLabels(): View
    {
        return view('arrays', ['labels' => [], 'params' => ['a' => $this->text()]]);
    }

    public function fixedLabels(): View
    {
        return view('arrays', ['labels' => [$this->text(), $this->text()], 'params' => ['a' => $this->text(), 'b' => $this->text()]]);
    }

    public function listedLabels(): View
    {
        return view('arrays', ['labels' => $this->labels(), 'params' => ['a' => $this->text()]]);
    }

    public function framed(): View
    {
        return view('framed', ['content' => view('fragment')]);
    }

    public function framedHost(): View
    {
        return view('framed-host');
    }

    public function framedNested(): View
    {
        return view('framed-nested', ['row' => ['v' => view('fragment')]]);
    }

    /** @return list<string> */
    private function labels(): array
    {
        return [$this->text()];
    }

    private function text(): string
    {
        return \str_repeat('x', \random_int(1, 3));
    }

    private function maybeAuthor(): ?Post
    {
        return \random_int(0, 1) === 1 ? new Post() : null;
    }

    /** @return Collection<int, Post> */
    private function posts(): Collection
    {
        return new Collection([new Post()]);
    }

    /** @return Collection<int, Video> */
    private function videos(): Collection
    {
        return new Collection([new Video()]);
    }
}
