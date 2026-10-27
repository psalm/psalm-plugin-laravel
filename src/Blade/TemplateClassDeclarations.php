<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * The named class-likes a compiled shadow declares (#2126). Psalm scans a shadow like a project
 * file, so a class a template declares competes for its FQCN with every other declaration, and the
 * loser's DuplicateClass also switches off the analysis of the winner.
 *
 * Token-gated: a shadow without a class-like keyword is never parsed, which is nearly every one.
 *
 * @internal
 */
final class TemplateClassDeclarations
{
    /**
     * @param array<array-key, array{0: int, 1: string, 2: int}|string> $tokens {@see \token_get_all()}
     *                                                                    output of `$shadowSource`
     *
     * @return list<array{name: string, line: int, start: int, end: int, suppressed: list<string>}>
     *         `name` is the FQCN without a leading backslash, `line` the shadow line of the declared
     *         name, `start` and `end` the inclusive byte range of the statement WITH the comments
     *         attached to it (a left-behind docblock would attach to the next statement), and
     *         `suppressed` the `@psalm-suppress` rules that cover the declared name; empty when the
     *         source does not parse or its names do not resolve
     */
    public static function find(string $shadowSource, array $tokens): array
    {
        if (!self::mentionsClassLike($tokens)) {
            return [];
        }

        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($shadowSource);

            // A clashing `use` alias parses and only fails here: that template declares nothing,
            // it must never take the run down.
            $statements = $statements === null ? [] : (new NodeTraverser(new NameResolver()))->traverse($statements);
        } catch (\Throwable) {
            return [];
        }

        $declarations = [];

        foreach ((new NodeFinder())->findInstanceOf($statements, ClassLike::class) as $node) {
            if ($node->name === null || $node->namespacedName === null) {
                continue;
            }

            $declarations[] = [
                'name' => $node->namespacedName->toString(),
                'line' => $node->name->getStartLine(),
                'start' => \min([$node->getStartFilePos(), ...\array_map(static fn(\PhpParser\Comment $comment): int => $comment->getStartFilePos(), $node->getComments())]),
                'end' => $node->getEndFilePos(),
                'suppressed' => EnclosingSuppressions::at($statements, $node->name->getStartFilePos(), $node->name->getEndFilePos()),
            ];
        }

        return $declarations;
    }

    /**
     * The source with each byte range overwritten by spaces, line breaks kept: every line number of
     * the shadow, and so its line map, stays valid.
     *
     * @param list<array{0: int, 1: int}> $ranges inclusive start and end byte offsets
     *
     * @psalm-pure
     */
    public static function blank(string $shadowSource, array $ranges): string
    {
        foreach ($ranges as [$start, $end]) {
            $length = $end - $start + 1;
            $blanked = \preg_replace('/[^\r\n]/', ' ', \substr($shadowSource, $start, $length)) ?? '';
            $shadowSource = \substr_replace($shadowSource, $blanked, $start, $length);
        }

        return $shadowSource;
    }

    /**
     * `Foo::class` and `new class` carry a `T_CLASS` too and name nothing; both are skipped here, so
     * a shadow is parsed only when it may declare a named class-like.
     *
     * @param array<array-key, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @psalm-pure
     */
    private static function mentionsClassLike(array $tokens): bool
    {
        $previous = null;

        foreach ($tokens as $token) {
            $id = \is_array($token) ? $token[0] : null;

            if (\in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if (\in_array($id, [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], true) && !\in_array($previous, [\T_DOUBLE_COLON, \T_NEW], true)) {
                return true;
            }

            $previous = $id;
        }

        return false;
    }
}
