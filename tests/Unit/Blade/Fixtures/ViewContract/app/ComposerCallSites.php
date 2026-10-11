<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

// Each statement is one case; the composer behind every view is registered in bootstrap/app.php.
final class ComposerCallSites
{
    // `title` and `tags` come from the composer, so only `count` is missing.
    public function literalMissing(): View
    {
        return view('composer-literal', []);
    }

    // A composer-supplied key is never type-checked (the composer may overwrite it), `count` still is.
    public function literalWrongType(): View
    {
        return view('composer-literal', ['title' => 123, 'tags' => 'x', 'count' => 'x']);
    }

    // The exact composer's `build` method and the wildcard composer's `compose` together supply
    // `count` and `title`; the exact composer's own `compose` (which would supply `other`) never runs.
    public function wildcardAndMethodUnion(): View
    {
        return view('composer-wild.page', []);
    }

    // `compose()` is declared in a trait that the parent class uses.
    public function inherited(): View
    {
        return view('composer-inherited', []);
    }

    public function creator(): View
    {
        return view('composer-created', []);
    }

    // Everything below stays whole-view silent: the key set cannot be proven.
    public function declined(): void
    {
        view('composer-dynamic', ['title' => 123]);
        view('composer-escape', ['title' => 123]);
        view('composer-conflict', ['title' => 123]);
        view('composer-unknown', ['title' => 123]);
    }
}
