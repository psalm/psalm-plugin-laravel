<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Support\Str;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;

/**
 * Literal-only parsing of Laravel's relation-aggregate calls (`withCount()`, `loadSum()`, ...).
 * Anything dynamic yields no entry: an entry is a PROOF that the aggregate attribute is loaded.
 *
 * @internal
 */
final class AggregateCallParser
{
    /**
     * Lowercased method name => [aggregate function, whether it is the Model::loadXxx() form].
     *
     * @var array<string, array{'count'|'exists'|'sum'|'min'|'max'|'avg', bool}>
     */
    private const METHODS = [
        'withcount' => ['count', false],
        'withexists' => ['exists', false],
        'withsum' => ['sum', false],
        'withmin' => ['min', false],
        'withmax' => ['max', false],
        'withavg' => ['avg', false],
        'loadcount' => ['count', true],
        'loadexists' => ['exists', true],
        'loadsum' => ['sum', true],
        'loadmin' => ['min', true],
        'loadmax' => ['max', true],
        'loadavg' => ['avg', true],
    ];

    /**
     * @return array{'count'|'exists'|'sum'|'min'|'max'|'avg', bool}|null
     * @psalm-pure
     */
    public static function describe(string $lowerMethodName): ?array
    {
        return self::METHODS[$lowerMethodName] ?? null;
    }

    /**
     * Splits the `relation as alias` clause exactly like QueriesRelationships::withAggregate():
     * three space-separated segments with a case-insensitive `as` in the middle.
     *
     * @return array{string, string|null}
     * @psalm-pure
     */
    public static function splitAlias(string $entry): array
    {
        $segments = \explode(' ', $entry);

        if (\count($segments) === 3 && \strtolower($segments[1]) === 'as') {
            return [$segments[0], $segments[2]];
        }

        return [$entry, null];
    }

    /**
     * The attribute name withAggregate() picks when no `as` clause is given. Calls Laravel's own
     * Str::snake() so the snake-casing rules cannot drift.
     *
     */
    public static function defaultAlias(string $relation, string $function, string $column): string
    {
        return Str::snake((string) \preg_replace(
            '/[^[:alnum:][:space:]_]/u',
            '',
            \sprintf('%s %s %s', $relation, $function, \strtolower($column)),
        ));
    }

    /**
     * @param 'count'|'exists'|'sum'|'min'|'max'|'avg' $function
     * @param array<array-key, Arg> $args
     * @return list<AggregateEntry>
     */
    public static function entries(string $function, array $args): array
    {
        foreach ($args as $arg) {
            if ($arg->unpack || $arg->name !== null) {
                return [];
            }
        }

        $first = ($args[0] ?? null)?->value;
        $column = '*';

        if ($function !== 'count' && $function !== 'exists') {
            $columnNode = ($args[1] ?? null)?->value;
            if (!$columnNode instanceof String_) {
                return [];
            }

            $column = $columnNode->value;
        }

        $names = [];

        if ($first instanceof Array_) {
            foreach ($first->items as $item) {
                if ($item === null || $item->unpack) {
                    continue;
                }

                // Keyed `['rel' => Closure]` names the relation by key; a list entry by value.
                if ($item->key instanceof String_) {
                    $names[] = $item->key->value;
                } elseif ($item->key === null && $item->value instanceof String_) {
                    $names[] = $item->value->value;
                }
            }
        } elseif ($first instanceof String_) {
            // Only withCount()/loadCount() read func_get_args(); the other forms take one $relations.
            $nodes = $function === 'count'
                ? \array_map(static fn(Arg $arg): Expr => $arg->value, $args)
                : [$first];

            foreach ($nodes as $node) {
                if ($node instanceof String_) {
                    $names[] = $node->value;
                }
            }
        }

        $entries = [];

        foreach ($names as $name) {
            [$relation, $alias] = self::splitAlias($name);

            // `rel:col1,col2` and dotted paths are not plain relation methods: no proof.
            if ($relation === '' || \strpbrk($relation, ':.') !== false) {
                continue;
            }

            $alias ??= self::defaultAlias($relation, $function, $column);

            if ($alias !== '') {
                $entries[] = new AggregateEntry($function, $relation, $alias, $column);
            }
        }

        return $entries;
    }
}
