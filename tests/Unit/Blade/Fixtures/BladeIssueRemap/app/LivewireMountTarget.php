<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture;

// A three-parameter receiver: both the precompiler-generated call (5 arguments) and the
// author-written calls in the templates (4 arguments) overshoot it, so both raise
// TooManyArguments; only the author-written ones are expected to survive the shadow-emission
// suppression (#1498). Returns a string so `{{ ... }}` can echo the call.
final class LivewireMountTarget
{
    public function mount(string $id, string $params, string $key): string
    {
        return $id . $params . $key;
    }
}
