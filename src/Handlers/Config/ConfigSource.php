<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Config;

use PhpParser\Node\Expr;

/**
 * The config-file expression that defines one config path, as {@see ConfigSourceIndex} found it.
 *
 * @internal
 */
final class ConfigSource
{
    /**
     * @param non-empty-list<Expr> $layers highest precedence first; more than one only where
     *        LoadConfiguration array_merge()s the app value over the framework base value
     * @param list<string> $mergeable keys whose values LoadConfiguration merges across the layers once more
     * @param bool $nestedFiles false below a dotted literal key, which no nested file path can reach
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly ConfigSourceIndex $index,
        private readonly string $path,
        private readonly array $layers,
        private readonly array $mergeable = [],
        private readonly bool $nestedFiles = true,
    ) {}

    /**
     * The expression that produced the value. For a merged path this is the app's array literal,
     * which is only ever walked through {@see child()}.
     *
     * @psalm-mutation-free
     */
    public function expression(): Expr
    {
        return $this->layers[0];
    }

    /** Null when the runtime value at $key cannot be attributed to one expression. */
    public function child(int|string $key): ?self
    {
        // PHP stores a decimal integer string key as an int.
        if (\is_string($key) && (string) (int) $key === $key) {
            $key = (int) $key;
        }

        $path = $this->path . '.' . $key;

        // config/foo/bar.php is set over config/foo.php's 'bar' entry. The loader splits file keys
        // on dots, so no file sets a dotted literal key 'foo.bar' or anything below it.
        $nestedFiles = $this->nestedFiles && (!\is_string($key) || !\str_contains($key, '.'));

        if ($nestedFiles && $this->index->hasFile($path)) {
            return $this->index->root($path);
        }

        // array_merge() renumbers integer keys.
        if (\is_int($key) && \count($this->layers) > 1) {
            return null;
        }

        $layers = [];
        foreach ($this->layers as $layer) {
            $items = ConfigSourceIndex::literalItems($layer);

            if ($items === null) {
                break;
            }

            if (!\array_key_exists($key, $items)) {
                continue;
            }

            $layers[] = $items[$key];

            if (!\in_array($key, $this->mergeable, true)) {
                break;
            }
        }

        return $layers === [] ? null : new self($this->index, $path, $layers, nestedFiles: $nestedFiles);
    }
}
