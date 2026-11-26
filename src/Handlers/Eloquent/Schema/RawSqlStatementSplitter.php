<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

/**
 * Splits the SQL string of a migration's `DB::statement()` / `DB::unprepared()` call into
 * statements and drops comments (`-- ...`, `# ...`, `/* ... *\/`), leaving quoted text alone, including
 * Postgres dollar-quoted bodies (`$$ ... $$`, `$tag$ ... $tag$`).
 * A schema dump parser can then read each statement without phantom tables or lost columns.
 *
 * Accepted limits: `#` is always a MySQL comment, so a Postgres `#>>` operator or a SQLite `[x#y]` identifier
 * drops the rest of its line and can lose that table, never invent one. Backslash escapes in quotes apply to every dialect.
 *
 * @internal
 * @psalm-immutable
 */
final class RawSqlStatementSplitter
{
    /**
     * @return list<string> Non-empty statements without comments or the trailing semicolon
     * @psalm-pure
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = \strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if (in_array($char, ["'", '"', '`'], true)) {
                $end = self::quoteEnd($sql, $i, $char);
                $current .= \substr($sql, $i, $end - $i);
                $i = $end - 1;
            } elseif ($char === '$' && \preg_match('/\G(?:\$[A-Za-z_][A-Za-z_0-9]*\$|\$\$)/', $sql, $tag, 0, $i) === 1) {
                $close = \strpos($sql, $tag[0], $i + \strlen($tag[0]));
                $end = $close === false ? $length : $close + \strlen($tag[0]);
                $current .= \substr($sql, $i, $end - $i);
                $i = $end - 1;
            } elseif ($char === '#' || ($char === '-' && ($sql[$i + 1] ?? '') === '-')) {
                $newline = \strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline - 1;
            } elseif ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $close = \strpos($sql, '*/', $i + 2);
                $i = $close === false ? $length : $close + 1;
                $current .= ' ';
            } elseif ($char === ';') {
                $statements[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $statements[] = $current;

        return \array_values(\array_filter(\array_map(\trim(...), $statements), static fn(string $statement): bool => $statement !== ''));
    }

    /**
     * Offset right after the quote that closes the one opened at $start (the end of input when unclosed).
     *
     * @psalm-pure
     */
    private static function quoteEnd(string $sql, int $start, string $quote): int
    {
        $length = \strlen($sql);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($sql[$i] === '\\' && $quote !== '`') {
                $i++; // MySQL backslash escape
            } elseif ($sql[$i] === $quote) {
                return $i + 1; // a doubled quote simply closes and reopens
            }
        }

        return $length;
    }
}
