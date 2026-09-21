<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use Psalm\Exception\TypeParseTreeException;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Records, per view name, the type every resolved `view()` call site passes for each data key —
 * the only sound type source {@see AnnotationWriter} has.
 *
 * Registered only in annotate mode, and only under `--threads=1`: Psalm's forked analysis workers
 * never hand their statics back to the parent, where the write runs.
 *
 * The confidence rule is deliberately narrow, because a wrong annotation becomes a false positive
 * at every call site that renders the template. A type is answered only when every producer of that
 * view agreed on it AND no producer left the view's data set open — an open set can carry the same
 * key with another type, invisibly.
 *
 * @internal
 */
final class AnnotationCollector implements AfterStatementAnalysisInterface
{
    private static bool $enabled = false;

    /**
     * Keyed by view name then variable name. `false` marks a key two producers disagreed on.
     *
     * @var array<string, array<string, Union|false>>
     */
    private static array $observed = [];

    /** @var array<string, true> views at least one producer left an open data set for */
    private static array $open = [];

    /** @psalm-external-mutation-free */
    public static function init(): void
    {
        self::$enabled = true;
    }

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$enabled = false;
        self::$observed = [];
        self::$open = [];
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        if (!self::$enabled) {
            return null;
        }

        // The same two statement shapes, and the same outermost-first walk, that
        // {@see \Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler} checks call sites with.
        $stmt = $event->getStmt();

        $expr = match (true) {
            $stmt instanceof Stmt\Expression, $stmt instanceof Stmt\Return_ => $stmt->expr,
            default => null,
        };

        if (!$expr instanceof Expr) {
            return null;
        }

        $chain = ViewCallChain::from($expr, $event->getStatementsSource());

        if ($chain instanceof ViewCallChain) {
            self::record($chain->viewName, $chain->data, $chain->complete);
        }

        return null;
    }

    /**
     * @param array<string, Union> $data     supplied variable name => the type passed for it
     * @param bool                 $complete whether the producer's key set was proven closed
     */
    public static function record(string $viewName, array $data, bool $complete): void
    {
        if (!$complete) {
            self::$open[$viewName] = true;
        }

        foreach ($data as $name => $type) {
            $seen = self::$observed[$viewName][$name] ?? null;

            if ($seen === false) {
                continue;
            }

            self::$observed[$viewName][$name] = $seen instanceof Union && $seen->getId(false) !== $type->getId(false)
                ? false
                : $type;
        }
    }

    /**
     * The type to declare for one variable, or null when nothing was proven and the caller should
     * fall back to `mixed`.
     */
    public static function typeFor(string $viewName, string $name): ?string
    {
        if (isset(self::$open[$viewName])) {
            return null;
        }

        $observed = self::$observed[$viewName][$name] ?? null;

        if (!$observed instanceof Union) {
            return null;
        }

        // Literal precision is dropped on purpose: two call sites passing 'draft' and 'published'
        // describe one `string` contract, and pinning either would be wrong for the other.
        $id = $observed->getId(false);

        // The id is about to be written into a template comment that Psalm parses back on the next
        // run. A type whose id does not survive that round trip (a template parameter, say) is no
        // annotation at all.
        try {
            Type::parseString($id);
        } catch (TypeParseTreeException) {
            return null;
        }

        return $id;
    }
}
