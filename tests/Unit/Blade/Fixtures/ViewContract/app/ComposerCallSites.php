<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

// Each method is one case; the composer behind every view is registered in bootstrap/app.php.
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

    // The wildcard composer supplies `title`, the exact `Class@build` one `count`; the class's own
    // `compose()`, which would supply `other`, never runs.
    public function wildcardAndMethodUnion(): View
    {
        return view('composer-wild.page', []);
    }

    // A creator registered as `Class@build` supplies `count`.
    public function creator(): View
    {
        return view('composer-created', []);
    }

    // `compose()` is declared in a trait that the parent class uses.
    public function inherited(): View
    {
        return view('composer-inherited', []);
    }

    // `section` comes from a composer that reads the view's path first.
    public function readOnlyAccessor(): View
    {
        return view('composer-accessor', []);
    }

    // One of the view's composers passes a computed key, so the view is checked for nothing.
    public function unknown(): View
    {
        return view('composer-dynamic', ['title' => 123]);
    }
}
