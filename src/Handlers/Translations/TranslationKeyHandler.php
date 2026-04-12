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
use Psalm\Type;
use Psalm\Type\Atomic\TNonEmptyArray;
use Psalm\Type\Union;

/**
 * Resolves return types for __() and trans() translation helpers.
 *
 * For literal string keys, uses Laravel's Translator to determine
 * whether the key exists and returns a precise type (non-empty-string,
 * non-empty-array, or string for missing keys). Optionally emits
 * MissingTranslation issues for keys not found in language files.
 *
 * For dynamic keys (variables, sprintf, concatenation), returns string
 * as a safe fallback — avoids the string|array union that causes
 * widespread PossiblyInvalidCast noise in real projects.
 *
 * Namespaced package keys (e.g., 'package::file.key') are skipped
 * since packages may not have their translations published.
 *
 * Falls back to the stub conditional return type for edge cases
 * like __() with no args (null) or trans() with no args (Translator).
 *
 * The findMissingTranslations config option controls only whether
 * MissingTranslation issues are emitted — type narrowing is always
 * active when the translator is available.
 *
 * @see https://laravel.com/docs/localization
 */
final class TranslationKeyHandler implements FunctionReturnTypeProviderInterface
{
    private static ?Translator $translator = null;

    /** Whether to emit MissingTranslation issues for keys not found in language files */
    private static bool $reportMissing = false;

    /**
     * Cached translation resolution results.
     *
     * - null value means the key does not exist (missing translation)
     * - Union value means the key exists and has been resolved to a precise type
     *
     * @var array<string, ?Union>
     */
    private static array $resolvedKeys = [];

    /** @psalm-external-mutation-free */
    public static function init(Translator $translator, bool $reportMissing): void
    {
        self::$translator = $translator;
        self::$reportMissing = $reportMissing;
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
            // __() returns null, trans() returns Translator — handled by stubs
            return null;
        }

        // Try to resolve literal string keys precisely via the Translator
        $translationKey = self::extractLiteralStringArg($callArgs[0]);

        if ($translationKey !== null) {
            $resolved = self::resolveTranslationType(
                $translationKey,
                $event->getCodeLocation(),
                $event->getStatementsSource()->getSuppressedIssues(),
            );

            if ($resolved instanceof \Psalm\Type\Union) {
                return $resolved;
            }
        }

        // Dynamic keys (variables, sprintf, concatenation) or missing literal keys:
        // return string to avoid PossiblyInvalidCast noise from string|array union
        $firstArgType = $event->getStatementsSource()->getNodeTypeProvider()->getType($callArgs[0]->value);

        if ($firstArgType instanceof \Psalm\Type\Union) {
            if ($firstArgType->isString()) {
                return Type::getString();
            }

            if ($firstArgType->isNullable() && $firstArgType->hasString()) {
                return Type::combineUnionTypes(Type::getString(), Type::getNull());
            }
        }

        // Non-string args (e.g. __(null)) — handled by stubs
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
     * Resolve the return type for a translation key, optionally emitting
     * MissingTranslation when the key does not exist and reporting is enabled.
     *
     * Skips namespaced keys (containing '::') since those belong to packages
     * whose translations may not be published to the app's lang/ directory.
     *
     * For existing keys, uses Translator::get() to determine whether the
     * resolved value is a string or an array, returning a precise type.
     *
     * @param array<array-key, string> $suppressedIssues
     */
    private static function resolveTranslationType(
        string $translationKey,
        CodeLocation $codeLocation,
        array $suppressedIssues,
    ): ?Union {
        if (!self::$translator instanceof \Illuminate\Translation\Translator) {
            return null;
        }

        // Skip namespaced package keys (e.g., 'pagination::pages.next') — packages
        // may not have their translations published to the app's lang/ directory
        if (\str_contains($translationKey, '::')) {
            return null;
        }

        if ($translationKey === '') {
            return null;
        }

        $resolvedType = self::resolveKey($translationKey);

        if ($resolvedType instanceof \Psalm\Type\Union) {
            return $resolvedType;
        }

        // Key does not exist — emit the issue only when findMissingTranslations
        // is enabled, then fall through to TransHandler for the fallback type
        if (self::$reportMissing) {
            IssueBuffer::accepts(
                new MissingTranslation(
                    "Translation key '{$translationKey}' not found in language files",
                    $codeLocation,
                ),
                $suppressedIssues,
            );
        }

        return null;
    }

    /**
     * Resolve a translation key to a precise Psalm type, caching the result.
     *
     * Uses Translator::has() to check existence, then Translator::get() to
     * determine whether the value is a string or an array.
     *
     * Returns null when the key does not exist. The null sentinel is stored
     * in the cache too, so missing keys are only looked up once.
     */
    private static function resolveKey(string $translationKey): ?Union
    {
        if (\array_key_exists($translationKey, self::$resolvedKeys)) {
            return self::$resolvedKeys[$translationKey];
        }

        // Caller (resolveTranslationType) guarantees $translator is set
        \assert(self::$translator instanceof Translator);

        try {
            $exists = self::$translator->has($translationKey);
        } catch (\Exception|\ParseError) {
            // Malformed language files can cause Translator::has() to throw:
            // - PHP syntax errors → \ParseError (subclass of \Error, not \Exception)
            // - Invalid JSON → \RuntimeException
            // - Custom loaders → other \Exception subclasses
            // Return string|array to avoid emitting a false MissingTranslation
            // for a key that may actually exist.
            $fallback = Type::combineUnionTypes(Type::getString(), Type::getArray());

            return self::$resolvedKeys[$translationKey] = $fallback;
        }

        if (!$exists) {
            self::$resolvedKeys[$translationKey] = null;

            return null;
        }

        try {
            $value = self::$translator->get($translationKey);
        } catch (\Exception|\ParseError) {
            // Key exists but value cannot be retrieved. Same failure modes as
            // has() above (\ParseError for PHP files, \RuntimeException for JSON).
            // Return string|array to avoid false positives, matching TransHandler's fallback.
            $fallback = Type::combineUnionTypes(Type::getString(), Type::getArray());

            return self::$resolvedKeys[$translationKey] = $fallback;
        }

        if (\is_array($value)) {
            $type = $value !== []
                ? new Union([new TNonEmptyArray([Type::getArrayKey(), Type::getMixed()])])
                : Type::getArray();
        } else {
            $type = $value !== ''
                ? Type::getNonEmptyString()
                : Type::getString();
        }

        self::$resolvedKeys[$translationKey] = $type;

        return $type;
    }
}
