<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Translations;

use Illuminate\Translation\Translator;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\MissingTranslation;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type\Union;

/**
 * Detects calls to __() and trans() with a translation key that does not
 * exist in the application's language files.
 *
 * Uses Laravel's Translator::has() from the booted application, which
 * handles PHP array files, JSON files, vendor/package namespaces, and
 * fallback locales automatically.
 *
 * Only string literal keys are checked — dynamic keys (variables,
 * concatenation) and namespaced package keys (e.g., 'package::file.key')
 * are skipped to avoid false positives.
 *
 * Must be registered before TransHandler in Plugin.php — Psalm stops
 * iterating handlers once one returns a non-null type. This handler
 * always returns null (issue-only), so TransHandler can still provide
 * the return type afterward.
 *
 * @see https://laravel.com/docs/localization
 */
final class MissingTranslationHandler implements FunctionReturnTypeProviderInterface
{
    private static ?Translator $translator = null;

    private static bool $enabled = false;

    /** @var array<string, bool> Cached translation existence results to avoid repeated Translator::has() calls */
    private static array $resolvedKeys = [];

    /** @psalm-external-mutation-free */
    public static function init(Translator $translator): void
    {
        self::$translator = $translator;
        self::$enabled = true;
        self::$resolvedKeys = [];
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['__', 'trans'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null;
        }

        $translationKey = self::extractLiteralStringArg($callArgs[0]);

        if ($translationKey === null) {
            return null;
        }

        self::checkTranslationExists(
            $translationKey,
            $event->getCodeLocation(),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /**
     * Extract a literal string value from a call argument's AST node.
     *
     * Returns null for non-literal arguments — the handler only validates
     * translation keys it can statically determine from the source code.
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
     * Check whether the given translation key exists in the application's language files.
     *
     * Skips namespaced keys (containing '::') since those belong to packages
     * whose translations may not be published to the app's lang/ directory.
     *
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkTranslationExists(
        string $translationKey,
        CodeLocation $codeLocation,
        array $suppressedIssues,
    ): void {
        if (!self::$enabled || self::$translator === null) {
            return;
        }

        // Skip namespaced package keys (e.g., 'pagination::pages.next') — packages
        // may not have their translations published to the app's lang/ directory
        if (\str_contains($translationKey, '::')) {
            return;
        }

        if ($translationKey === '') {
            return;
        }

        if (self::translationExists($translationKey)) {
            return;
        }

        IssueBuffer::accepts(
            new MissingTranslation(
                "Translation key '{$translationKey}' not found in language files",
                $codeLocation,
            ),
            $suppressedIssues,
        );
    }

    /**
     * Check if a translation key exists, caching the result.
     *
     * Translator::has() involves parseKey(), group loading, and array lookups.
     * Caching avoids this overhead for repeated keys (common in large codebases).
     */
    private static function translationExists(string $translationKey): bool
    {
        if (self::$translator === null) {
            return true;
        }

        return self::$resolvedKeys[$translationKey]
            ??= self::$translator->has($translationKey);
    }
}
