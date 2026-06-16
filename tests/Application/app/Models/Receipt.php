<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Sibling of Contract under AbstractDocument: a second concrete child that inherits the
 * parent-composed ComparesRank scopes. Exists so a trait scope's `self` param — which binds
 * to the composing parent (AbstractDocument), not the queried child — can be exercised with a
 * *sibling* argument: `Contract::query()->rankedAbove($receipt)` is runtime-valid and must
 * type-check (issue #1031). A queried-model `self` pin would wrongly reject it.
 *
 * Minimal by design (mirrors Contract): the only archetype it adds is "second child of an
 * abstract parent that hosts a trait scope".
 */
final class Receipt extends AbstractDocument
{
    protected $table = 'receipts';
}
