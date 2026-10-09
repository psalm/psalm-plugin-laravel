<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Scalar\String_;

/**
 * Harvests fully-qualified class-name CANDIDATES from PHP string literals in a compiled shadow
 * (#1505): a vendor directive that compiles a class name into a string argument
 * (`app('Vendor\Package\Class')::method()`) never puts it in code position or a docblock, so
 * neither Psalm's own scanner nor {@see PreludeBuilder::ambientClassNames()} ever sees it, and the
 * class is reported UndefinedClass the first time nothing else in the project names it.
 *
 * Deliberately string-literal-only: `T_CONSTANT_ENCAPSED_STRING` excludes both the prelude's
 * stacked `@var` docblocks (already handled by `queueClassLikesForScanning()`, `T_DOC_COMMENT`) and
 * interpolated double-quoted strings (`T_ENCAPSED_AND_WHITESPACE`), which are not literals and
 * cannot name a class this way.
 *
 * @internal
 */
final class ClassLiteralCollector
{
    /**
     * A candidate needs at least one namespace separator: a bare global class name in a literal
     * (`'DateTime'`) is deliberately missed, conservatively, since global classes resolve through
     * Psalm's own reflection anyway and the separator is what tells a class name apart from a view
     * name, a regex, or a Windows path in the same token stream.
     */
    private const CANDIDATE_PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)+$/';

    /**
     * @return list<string> deduped, `\`-stripped FQCN candidates found in the source's string
     *                      literals
     */
    public function collectFromSource(string $php): array
    {
        $candidates = [];

        foreach (\token_get_all($php) as $token) {
            if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $candidate = $this->asCandidate($token[1]);

            if ($candidate !== null) {
                $candidates[$candidate] = true;
            }
        }

        return \array_keys($candidates);
    }

    private function asCandidate(string $tokenText): ?string
    {
        try {
            // String_::parse() is the only correct unescaper for both quote styles (stripslashes and
            // stripcslashes each mishandle one of them); php-parser marks it internal but it is stable.
            /** @psalm-suppress InternalMethod */
            $value = String_::parse($tokenText);
        } catch (\Throwable) {
            // Malformed escape sequence in a token PHP itself already tokenized: not our problem to
            // solve, just skip it — the same doctrine as an unparseable shadow elsewhere in Blade
            // analysis.
            return null;
        }

        if (\preg_match(self::CANDIDATE_PATTERN, $value) !== 1) {
            return null;
        }

        return \ltrim($value, '\\');
    }
}
