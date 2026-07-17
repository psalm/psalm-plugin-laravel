<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Narrows Collection::all() to list<TValue> when the call is chained
 * immediately after a receiver that is guaranteed to reindex sequentially:
 * values() (i.e. `$c->values()->all()`) or single-argument pluck() (i.e.
 * `$c->pluck('col')->all()`).
 *
 * The chain is the only call shape where the result is guaranteed to be a
 * list. values() calls array_values() (or a generator yielding without
 * keys for LazyCollection); a one-argument pluck() has no $key column to
 * key by, so Arr::pluck() falls through to sequential int keys too. Either
 * way the inner array always has consecutive 0-based integer keys.
 *
 * A purely stub-based conditional return on all() (e.g. keyed on
 * `TKey is int<0, max>`) would mislabel any int-keyed collection whose
 * keys merely fit the bound without being contiguous (e.g. [1 => ..., 3 => ...]).
 * AST chain detection avoids that class of false positives entirely.
 *
 * Known limitations (both purely syntactic — Psalm's stubbed return type
 * applies instead of list<TValue>):
 *  - Variable-bound form: `$v = $c->values(); $v->all();`. The receiver
 *    of all() is a Variable, not an immediate values()/pluck() MethodCall.
 *  - Nullsafe operators: `$c?->values()?->all()`. Psalm's NullsafeAnalyzer
 *    desugars `?->all()` into a VirtualMethodCall (subclass of MethodCall)
 *    whose receiver is a synthesized temp variable, not the inner values()
 *    call, so the receiver-shape check in isImmediateChainFromReindex fails.
 * Users who need the list shape should keep the chain inline and avoid
 * nullsafe on the inner call.
 */
final class CollectionReindexAllHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Collection::class, LazyCollection::class, EloquentCollection::class];
    }

    /** @psalm-mutation-free */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'all') {
            return null;
        }

        $stmt = $event->getStmt();
        if (!$stmt instanceof MethodCall) {
            return null;
        }

        if (!self::isImmediateChainFromReindex($stmt)) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();
        if ($templateTypeParameters === null || \count($templateTypeParameters) < 2) {
            return null;
        }

        $tValue = $templateTypeParameters[1];

        return new Union([Type::getListAtomic($tValue, from_docblock: true)]);
    }

    /**
     * True when $stmt is `<expr>->values()->all()` or `<expr>->pluck($col)->all()`
     * — the receiver of all() is itself an immediate values() call (any arity) or
     * a single-argument pluck() call. This excludes any intermediate method (e.g.
     * ->values()->filter()->all()) and variable-bound chains.
     *
     * Two-argument pluck() (a $key column is given) is excluded: Arr::pluck() then
     * keys the result by that column, so the output is not list-shaped.
     *
     * @psalm-mutation-free
     */
    private static function isImmediateChainFromReindex(MethodCall $stmt): bool
    {
        $receiver = $stmt->var;
        if (!$receiver instanceof MethodCall) {
            return false;
        }

        if (!$receiver->name instanceof Identifier) {
            return false;
        }

        $receiverName = \strtolower($receiver->name->name);

        if ($receiverName === 'values') {
            return true;
        }

        if ($receiverName !== 'pluck') {
            return false;
        }

        if ($receiver->args === []) {
            return true;
        }

        // pluck(...$cols) is a single Arg with unpack=true — $cols may carry a
        // $key column at runtime, so it can't be trusted to be list-shaped.
        return \count($receiver->args) === 1
            && $receiver->args[0] instanceof Arg
            && !$receiver->args[0]->unpack;
    }
}
