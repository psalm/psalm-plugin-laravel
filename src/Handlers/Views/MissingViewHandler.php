<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\View\Factory;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\MissingView;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Union;

/**
 * Detects calls to view() and View::make() with a view name that does not
 * correspond to an existing Blade template file.
 *
 * Only string literal view names are checked — dynamic names and namespaced
 * views (e.g., 'mail::html.header') are skipped to avoid false positives.
 *
 * @see https://laravel.com/docs/views
 */
final class MissingViewHandler implements FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    /** @var list<string> Absolute paths to view directories */
    private static array $viewPaths = [];

    private static bool $enabled = false;

    /** @var array<string, bool> Cached view existence results to avoid repeated filesystem checks */
    private static array $resolvedViews = [];

    /**
     * @param list<string> $viewPaths Absolute paths to view directories (from config('view.paths'))
     * @psalm-external-mutation-free
     */
    public static function init(array $viewPaths): void
    {
        self::$viewPaths = \array_map(
            static fn(string $path): string => \rtrim($path, \DIRECTORY_SEPARATOR),
            $viewPaths,
        );
        self::$enabled = true;
        self::$resolvedViews = [];
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['view'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null;
        }

        $viewName = self::extractLiteralStringArg($callArgs[0]);

        if ($viewName === null) {
            return null;
        }

        self::checkViewExists(
            $viewName,
            $event->getCodeLocation(),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Factory::class];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'make') {
            return null;
        }

        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null;
        }

        $viewName = self::extractLiteralStringArg($callArgs[0]);

        if ($viewName === null) {
            return null;
        }

        self::checkViewExists(
            $viewName,
            $event->getCodeLocation(),
            $event->getSource()->getSuppressedIssues(),
        );

        return null;
    }

    /**
     * Extract a literal string value from a call argument's AST node.
     *
     * Returns null for non-literal arguments — the handler only validates
     * view names it can statically determine from the source code.
     *
     * @psalm-mutation-free
     */
    private static function extractLiteralStringArg(Arg $arg): ?string
    {
        $value = $arg->value;

        if ($value instanceof String_) {
            return $value->value;
        }

        return null;
    }

    /**
     * Check whether the given view name resolves to an existing template file.
     *
     * Skips namespaced views (containing '::') since those are resolved through
     * package-registered paths that the plugin may not know about yet.
     *
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkViewExists(string $viewName, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if (!self::$enabled) {
            return;
        }

        // Skip namespaced views (e.g., 'mail::html.header') — they resolve
        // through package-registered paths we don't track yet
        if (\str_contains($viewName, '::')) {
            return;
        }

        if ($viewName === '') {
            return;
        }

        if (self::viewFileExists($viewName)) {
            return;
        }

        IssueBuffer::accepts(
            new MissingView(
                "View '{$viewName}' not found in any of the registered view paths",
                $codeLocation,
            ),
            $suppressedIssues,
        );
    }

    /**
     * Check if a view file exists in any of the configured view paths.
     *
     * Mirrors Laravel's FileViewFinder::findInPaths() logic:
     * converts dot notation to directory separators, then tries
     * each extension in order.
     */
    private static function viewFileExists(string $viewName): bool
    {
        if (isset(self::$resolvedViews[$viewName])) {
            return self::$resolvedViews[$viewName];
        }

        // Convert dot notation to path: 'emails.welcome' → 'emails/welcome'
        $relativePath = \str_replace('.', \DIRECTORY_SEPARATOR, $viewName);

        foreach (self::$viewPaths as $basePath) {
            foreach (['.blade.php', '.php'] as $extension) {
                if (\file_exists($basePath . \DIRECTORY_SEPARATOR . $relativePath . $extension)) {
                    self::$resolvedViews[$viewName] = true;

                    return true;
                }
            }
        }

        self::$resolvedViews[$viewName] = false;

        return false;
    }
}
