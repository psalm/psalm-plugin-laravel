<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

/**
 * The textual shape of a relation path as Laravel's eager-load and relation-query methods read it:
 * `posts.comments` walks relations, `posts:id,title` selects columns of the relation it follows.
 *
 * Only the segmentation is shared. What a consumer does per segment differs (the validation rule checks
 * existence and tolerates gaps; the callback typer needs an exact Relation type and declines on a MorphTo), so
 * those guards stay at the consumer: a guard inside the shared walk would change the other consumer's verdicts.
 *
 * @internal
 * @psalm-immutable
 */
final class RelationPath
{
    /**
     * The dot-separated segments of `$path`.
     *
     * With `$allowsColumns` each segment loses its `:col1,col2` suffix. Laravel applies the suffix to the whole
     * name before it splits on dots, so a consumer that models the runtime strips it with {@see withoutColumns()}
     * first and passes false here.
     *
     * @return non-empty-list<string>
     * @psalm-pure
     */
    public static function segments(string $path, bool $allowsPaths, bool $allowsColumns): array
    {
        $segments = $allowsPaths ? \explode('.', $path) : [$path];

        return $allowsColumns
            ? \array_map(static fn(string $segment): string => self::withoutColumns($segment), $segments)
            : $segments;
    }

    /**
     * `posts:id,title` => `posts`.
     *
     * @psalm-pure
     */
    public static function withoutColumns(string $name): string
    {
        return \explode(':', $name, 2)[0];
    }
}
