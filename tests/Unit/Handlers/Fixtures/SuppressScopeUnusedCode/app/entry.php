<?php

declare(strict_types=1);

namespace ScopeUnusedCodeFixture;

use ScopeUnusedCodeFixture\Models\TraitScopeModel;

// Reference the model so it is not reported UnusedClass; deliberately do NOT dispatch any scope,
// mirroring real code where scopes are only ever called through the query builder. The strict
// (errorLevel=1) run may add unrelated notes here (e.g. MissingPureAnnotation); that is fine — the
// test filters findings to unused-method types, so only the scope/accessor suppression is asserted.
function consume(TraitScopeModel $model): string
{
    return $model::class;
}
