<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Psalm\DocComment;
use Psalm\Exception\DocblockParseException;

/**
 * The `@psalm-suppress` rules a shadow's own docblocks apply to a byte range, which is what Psalm
 * itself would have checked an issue at that range against. Shared by the issue remap, whose event
 * omits the list, and by findings the plugin emits itself on a template.
 *
 * @internal
 */
final class EnclosingSuppressions
{
    /**
     * @param array<array-key, Node> $statements the shadow's parsed statements
     *
     * @return list<string>
     */
    public static function at(array $statements, int $rangeStart, int $rangeEnd): array
    {
        // Only enclosing statements can contribute: a sibling's docblock must never become a
        // template-wide suppression. A type issue can point inside the enclosing node's own
        // docblock, before its first code token.
        $enclosing = (new NodeFinder())->find($statements, static fn(Node $node): bool
            => ($node instanceof Node\Stmt || $node instanceof Node\FunctionLike || $node instanceof Node\MatchArm)
            && ($node->getDocComment()?->getStartFilePos() ?? $node->getStartFilePos()) <= $rangeStart
            && $node->getEndFilePos() >= $rangeEnd);
        $rules = [];
        foreach ($enclosing as $node) {
            // MatchAnalyzer suppresses these two across the arms of a `match` through a runtime list
            // the event omits (upstream gap). The subject, and a `match` used as an operand, sit
            // outside every arm and keep reporting (#1801). Residual: with no default arm Psalm
            // analyzes the arm conditions again unsuppressed and can report there, a template stays
            // silent.
            if ($node instanceof Node\MatchArm) {
                $rules[] = 'RedundantCondition';
                $rules[] = 'RedundantConditionGivenDocblockType';

                continue;
            }

            foreach ($node->getComments() as $comment) {
                if (!$comment instanceof Doc) {
                    continue;
                }

                try {
                    $parsed = DocComment::parsePreservingLength($comment);
                } catch (DocblockParseException) {
                    continue;
                }

                foreach ($parsed->tags['psalm-suppress'] ?? [] as $entry) {
                    foreach (DocComment::parseSuppressList($entry) as $rule) {
                        $rules[] = $rule;
                    }
                }
            }
        }

        return $rules;
    }
}
