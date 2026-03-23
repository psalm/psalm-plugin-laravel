<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

/**
 * Parses SQL schema dump files (from `php artisan schema:dump`) and populates
 * SchemaAggregator with discovered tables and columns.
 *
 * Supports MySQL (mysqldump), PostgreSQL (pg_dump), and SQLite (sqlite3 .schema) output formats.
 * SQL schema dumps represent the base state from squashed migrations —
 * they are parsed before PHP migration files so that subsequent migrations
 * can alter the base schema.
 *
 * @internal
 * @psalm-external-mutation-free
 */
final class SqlSchemaParser
{
    /**
     * SQL keywords that begin non-column lines inside CREATE TABLE blocks.
     * Used to distinguish column definitions from constraints and indexes.
     *
     * @var list<string>
     */
    private const NON_COLUMN_KEYWORDS = [
        'PRIMARY',
        'KEY',
        'CONSTRAINT',
        'UNIQUE',
        'INDEX',
        'CHECK',
        'EXCLUDE',
        'FOREIGN',
    ];

    /**
     * Parse a SQL schema dump and add discovered tables to the aggregator.
     *
     * Each CREATE TABLE statement becomes a SchemaTable with SchemaColumn entries.
     * Tables already present in the aggregator (e.g. from a previously parsed file)
     * are overwritten — this matches the behavior of loading a fresh schema dump.
     *
     * @psalm-external-mutation-free
     */
    public function addToAggregator(string $sql, SchemaAggregator $aggregator): void
    {
        // Find each CREATE TABLE statement and extract the table name + opening paren position.
        // Then use paren-depth counting to find the matching closing paren, which correctly
        // handles nested parens in types like varchar(255), enum('a','b'), foreign key(...).
        // This works for both single-line (SQLite .dump) and multi-line (MySQL/PostgreSQL) formats.
        $offset = 0;
        while (\preg_match(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:\w+\.)?(?:`([^`]+)`|"([^"]+)"|(\w+))\s*\(/i',
            $sql,
            $match,
            \PREG_OFFSET_CAPTURE,
            $offset,
        )) {
            $tableName = ($match[1][0] ?: $match[2][0]) ?: $match[3][0];

            // Position right after the opening paren
            /** @var int $bodyStart */
            $bodyStart = $match[0][1] + \strlen($match[0][0]);

            $body = $this->extractParenBody($sql, $bodyStart);
            if ($body === null) {
                // Malformed SQL — skip to avoid infinite loop
                $offset = $bodyStart;
                continue;
            }

            $table = new SchemaTable();

            // Split the body into individual column/constraint definitions.
            // We split on commas that are not inside parentheses (to preserve
            // types like varchar(255), enum('a','b'), and foreign key(...) clauses).
            foreach ($this->splitColumnDefinitions($body) as $columnDef) {
                $column = $this->parseColumnLine($columnDef);
                if ($column instanceof SchemaColumn) {
                    $table->setColumn($column);
                }
            }

            $aggregator->setTable($tableName, $table);
            $offset = $bodyStart + \strlen($body);
        }
    }

    /**
     * Extract text from a position up to the matching closing parenthesis.
     *
     * Starts after an already-consumed opening paren and counts nested parens
     * (respecting single-quoted strings) to find the balanced closing paren.
     *
     * @param int $start Position in $sql right after the opening paren
     * @return string|null The body between parens, or null if unbalanced
     * @psalm-pure
     */
    private function extractParenBody(string $sql, int $start): ?string
    {
        $depth = 1;
        $length = \strlen($sql);
        $inQuote = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inQuote) {
                if ($char === '\\') {
                    // Backslash escape (MySQL): skip the next character entirely
                    $i++;
                } elseif ($char === "'") {
                    // SQL-standard escaped quote (''): skip the pair
                    if ($i + 1 < $length && $sql[$i + 1] === "'") {
                        $i++;
                    } else {
                        $inQuote = false;
                    }
                }
            } elseif ($char === "'") {
                $inQuote = true;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return \substr($sql, $start, $i - $start);
                }
            }
        }

        return null;
    }

    /**
     * Split a CREATE TABLE body into individual column/constraint definitions.
     *
     * Splits on commas that are not nested inside parentheses, so types like
     * `enum('a','b')` and `decimal(10,2)` are kept intact.
     *
     * @return list<string>
     * @psalm-pure
     */
    private function splitColumnDefinitions(string $body): array
    {
        $definitions = [];
        $current = '';
        $depth = 0;
        $inQuote = false;

        $length = \strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($inQuote) {
                $current .= $char;
                if ($char === '\\') {
                    // Backslash escape (MySQL): consume the next character as-is
                    if ($i + 1 < $length) {
                        $i++;
                        $current .= $body[$i];
                    }
                } elseif ($char === "'") {
                    // SQL-standard escaped quote (''): consume the pair
                    if ($i + 1 < $length && $body[$i + 1] === "'") {
                        $i++;
                        $current .= "'";
                    } else {
                        $inQuote = false;
                    }
                }
            } elseif ($char === "'") {
                $inQuote = true;
                $current .= $char;
            } elseif ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $trimmed = \trim($current);
                if ($trimmed !== '') {
                    $definitions[] = $trimmed;
                }

                $current = '';
            } else {
                $current .= $char;
            }
        }

        $trimmed = \trim($current);
        if ($trimmed !== '') {
            $definitions[] = $trimmed;
        }

        return $definitions;
    }

    /**
     * Parse a single column definition line.
     *
     * Returns null for non-column lines (constraints, indexes, etc.)
     *
     * @psalm-mutation-free
     */
    private function parseColumnLine(string $line): ?SchemaColumn
    {
        // MySQL: `column_name` type_info ...,
        if (\preg_match('/^`([^`]+)`\s+(.+?),?\s*$/', $line, $match)) {
            return $this->buildColumn($match[1], $match[2]);
        }

        // PostgreSQL: "column_name" type_info ...,
        if (\preg_match('/^"([^"]+)"\s+(.+?),?\s*$/', $line, $match)) {
            return $this->buildColumn($match[1], $match[2]);
        }

        // PostgreSQL unquoted: column_name type_info ...,
        if (\preg_match('/^(\w+)\s+(.+?),?\s*$/', $line, $match)) {
            // Skip constraint/index lines that start with SQL keywords
            if (\in_array(\strtoupper($match[1]), self::NON_COLUMN_KEYWORDS, true)) {
                return null;
            }

            return $this->buildColumn($match[1], $match[2]);
        }

        return null;
    }

    /**
     * Build a SchemaColumn from a column name and its SQL definition string.
     *
     * Example definitions (after column name extraction):
     * - "int unsigned NOT NULL AUTO_INCREMENT"
     * - "varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL"
     * - "tinyint(1) NOT NULL DEFAULT '0'"
     * - "timestamp NULL DEFAULT NULL"
     * - "enum('draft','published') NOT NULL DEFAULT 'draft'"
     *
     * @psalm-mutation-free
     */
    private function buildColumn(string $name, string $definition): SchemaColumn
    {
        // Strip CHARACTER SET and COLLATE clauses — noise for type resolution
        $def = \preg_replace('/\bCHARACTER\s+SET\s+\S+/i', '', $definition) ?? $definition;
        $def = \preg_replace('/\bCOLLATE\s+\S+/i', '', $def) ?? $def;
        $def = \preg_replace('/\s+/', ' ', \trim($def)) ?? \trim($def);

        $defUpper = \strtoupper($def);

        $nullable = !\str_contains($defUpper, 'NOT NULL');
        $unsigned = \str_contains($defUpper, 'UNSIGNED');

        $typeStr = $this->extractBaseType($def);

        // Extract enum/set options: enum('a','b','c') or set('x','y')
        $options = [];
        if (\preg_match('/^(?:enum|set)\s*\((.+)\)/i', $typeStr, $enumMatch)) {
            // Match SQL string literals, allowing escaped quotes ('') and backslash escapes (\')
            \preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/", $enumMatch[1], $optionMatches);
            $options = \array_map(self::unescapeSqlString(...), $optionMatches[1]);
        }

        $type = $this->mapSqlType($typeStr, $options !== []);
        $default = $this->extractDefault($def);

        return new SchemaColumn($name, $type, $nullable, $options, $default, $unsigned);
    }

    /**
     * Extract the SQL type from a column definition, stopping at modifier keywords.
     *
     * Given "varchar(255) NOT NULL DEFAULT 'foo'", returns "varchar(255)".
     * Given "timestamp(0) without time zone NOT NULL", returns "timestamp(0) without time zone".
     * Given "int unsigned NOT NULL", returns "int" (UNSIGNED is a modifier).
     *
     * @psalm-pure
     */
    private function extractBaseType(string $def): string
    {
        // Match everything up to the first modifier keyword.
        // The non-greedy quantifier ensures we stop at the earliest modifier.
        $modifiers = 'UNSIGNED|NOT\s+NULL|(?<!\w)NULL(?!\w)|DEFAULT|AUTO_INCREMENT'
            . '|COMMENT|ON\s+UPDATE|PRIMARY|UNIQUE|GENERATED|REFERENCES|CHECK|CONSTRAINT';

        if (\preg_match('/^(.+?)(?:\s+(?:' . $modifiers . ')\b|$)/i', $def, $match)) {
            return \trim($match[1]);
        }

        return $def;
    }

    /**
     * Map a SQL type string to a SchemaColumn type constant.
     *
     * @param bool $hasOptions Whether enum/set options were found
     * @psalm-pure
     */
    private function mapSqlType(string $typeStr, bool $hasOptions): string
    {
        $typeLower = \strtolower(\trim($typeStr));

        // enum/set with options
        if ($hasOptions) {
            if (\str_starts_with($typeLower, 'enum')) {
                return SchemaColumn::TYPE_ENUM;
            }

            if (\str_starts_with($typeLower, 'set')) {
                // No TYPE_SET constant — uses literal 'set' to match SchemaAggregator behavior
                return 'set';
            }
        }

        // tinyint(1) is boolean in Laravel (MySQL's BOOL is an alias for TINYINT(1))
        if (\preg_match('/^tinyint\s*\(\s*1\s*\)$/', $typeLower)) {
            return SchemaColumn::TYPE_BOOL;
        }

        // Strip parenthesized parameters and PostgreSQL type modifiers for base matching:
        // "varchar(255)" → "varchar", "decimal(5,2)" → "decimal",
        // "timestamp(0) without time zone" → "timestamp without time zone"
        $baseType = \strtolower(\trim(\preg_replace('/\([^)]*\)/', '', $typeLower) ?? $typeLower));

        // For multi-word types, the first word determines the category
        $firstWord = \explode(' ', $baseType)[0];

        return match ($firstWord) {
            // Integer types (MySQL + PostgreSQL)
            'int', 'integer', 'bigint', 'mediumint', 'smallint', 'tinyint',
            'serial', 'bigserial', 'smallserial' => SchemaColumn::TYPE_INT,

            // Float types
            'decimal', 'numeric', 'float', 'double', 'real' => SchemaColumn::TYPE_FLOAT,

            // Boolean (PostgreSQL uses boolean/bool directly; MySQL tinyint(1) handled above)
            'boolean', 'bool' => SchemaColumn::TYPE_BOOL,

            // String types — dates/times, text, binary, JSON, network types
            'varchar', 'char', 'character', 'text', 'tinytext', 'mediumtext', 'longtext', 'clob',
            'date', 'datetime', 'timestamp', 'time', 'timetz', 'timestamptz', 'year',
            'json', 'jsonb', 'uuid',
            'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea',
            'inet', 'cidr', 'macaddr', 'citext' => SchemaColumn::TYPE_STRING,

            // enum/set without options (rare, but handle gracefully)
            'enum' => SchemaColumn::TYPE_ENUM,
            'set' => 'set',

            default => SchemaColumn::TYPE_MIXED,
        };
    }

    /**
     * Extract a DEFAULT value from a column definition string.
     *
     * Returns null when no DEFAULT clause is present.
     *
     * @psalm-pure
     */
    private function extractDefault(string $def): ?SchemaColumnDefault
    {
        // Match DEFAULT followed by value, stopping at the next modifier keyword or end of string.
        // The value can be: NULL, a quoted string, a number, true/false, or a function call.
        if (!\preg_match(
            '/\bDEFAULT\s+(.+?)(?:\s+(?:NOT\s+NULL|(?<!\w)NULL(?!\w)|AUTO_INCREMENT|COMMENT|ON\s+UPDATE)\b|,?\s*$)/i',
            $def,
            $match,
        )) {
            return null;
        }

        $value = \trim($match[1]);
        $valueUpper = \strtoupper($value);

        if ($valueUpper === 'NULL') {
            return SchemaColumnDefault::resolved(null);
        }

        if ($valueUpper === 'TRUE') {
            return SchemaColumnDefault::resolved(true);
        }

        if ($valueUpper === 'FALSE') {
            return SchemaColumnDefault::resolved(false);
        }

        // Quoted string: 'value' (SQL uses single quotes for string literals)
        if (\preg_match("/^'(.*)'$/s", $value, $strMatch)) {
            // Unescape SQL string: '' → ' and \' → ' and \\ → \
            $unescaped = \str_replace(["''", "\\'", "\\\\"], ["'", "'", "\\"], $strMatch[1]);

            return SchemaColumnDefault::resolved($unescaped);
        }

        // Numeric: integer, float, or negative (is_numeric covers all these)
        if (\is_numeric($value)) {
            return \str_contains($value, '.')
                ? SchemaColumnDefault::resolved((float) $value)
                : SchemaColumnDefault::resolved((int) $value);
        }

        // Function calls (NOW(), CURRENT_TIMESTAMP, etc.) — not statically resolvable
        return SchemaColumnDefault::unresolvable();
    }

    /**
     * Unescape a SQL string literal so it matches PHP string values from migrations.
     *
     * Handles SQL-standard doubled quotes ('' → ') and MySQL backslash escapes (\' → ', \\ → \).
     *
     * @psalm-pure
     */
    private function unescapeSqlString(string $value): string
    {
        return \str_replace(["''", "\\'", "\\\\"], ["'", "'", "\\"], $value);
    }
}
