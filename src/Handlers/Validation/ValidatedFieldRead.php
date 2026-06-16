<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

/**
 * One recognized read of validated request data, regardless of the syntax
 * that produced it. The three "front doors" — a keyed accessor method
 * (`$req->input('email')`, `$safe->input('email')`), a magic property fetch
 * (`$req->email`), and a tracked local that aliases one of those
 * (`$v = $req->input('email')`) — all resolve to this single shape via
 * {@see ValidatedFieldReadResolver::resolve}.
 *
 * Carries only the two facets the taint handler needs:
 *
 *   - {@see $sourceTaints}: the taint kinds to (re-)introduce on the read.
 *     Non-zero when the validated value originates user input and the stub's
 *     `@psalm-taint-source` was dropped by a return-type / property-type
 *     override (or never existed, as on `Request::__get`). See #11765.
 *   - {@see $removedTaints}: the taint kinds the field's validation rule
 *     guarantees cannot be present (e.g. an `email` rule escapes header and
 *     cookie taint), applied by {@see ValidationTaintHandler::removeTaints}.
 *
 * A variable alias has `sourceTaints === 0` (the underlying accessor already
 * sourced the value; only the escape needs to ride the assignment edge).
 *
 * @psalm-immutable
 */
final readonly class ValidatedFieldRead
{
    public function __construct(
        public int $sourceTaints,
        public int $removedTaints,
    ) {}
}
