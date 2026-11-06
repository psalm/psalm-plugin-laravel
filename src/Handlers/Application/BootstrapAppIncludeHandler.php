<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Application;

use PhpParser\Node\Expr\Include_;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Types `require __DIR__ . '/../bootstrap/app.php'` (in `artisan`, `public/index.php`) as the booted Application.
 *
 * The plugin already booted that file in-process, so Psalm's IncludeAnalyzer returns early for an included
 * file without setting the node type: the `$app` that `artisan` and `public/index.php` then call stays `mixed`.
 * Declines (null type left untouched) unless the include is the exact file the plugin booted from.
 */
final class BootstrapAppIncludeHandler implements AfterExpressionAnalysisInterface
{
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof Include_) {
            return null;
        }

        $typeProvider = $event->getStatementsSource()->getNodeTypeProvider();
        $current = $typeProvider->getType($expr);

        if ($current instanceof Union && !$current->isMixed()) {
            return null;
        }

        $pathType = $typeProvider->getType($expr->expr);

        if (!$pathType instanceof Union || !$pathType->isSingleStringLiteral()) {
            return null;
        }

        $bootPath = ApplicationProvider::getBootPath();

        if ($bootPath === null || ApplicationProvider::getBootMode() !== 'bootstrap') {
            return null;
        }

        $included = \realpath($pathType->getSingleStringLiteral()->value);

        if ($included === false || $included !== \realpath($bootPath)) {
            return null;
        }

        $typeProvider->setType($expr, new Union([new TNamedObject(ApplicationProvider::getAppFullyQualifiedClassName())]));

        return null;
    }
}
