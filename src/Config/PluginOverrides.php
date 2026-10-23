<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Config;

/**
 * Per-run plugin setting overrides from `psalm-laravel analyze --plugin-option`, received through
 * {@see self::ENV_VAR} as whitespace-separated `KEY=VALUE` tokens, last wins.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class PluginOverrides
{
    /** Internal transport from `psalm-laravel analyze` to the child psalm, not a user contract. */
    public const ENV_VAR = 'PSALM_LARAVEL_INTERNAL_OPTIONS';

    private const BOOL = ['true', 'false'];

    /**
     * Overridable keys (XML names, nested ones dotted) and their accepted values. Only bool and enum
     * settings, so no value contains whitespace; paths (`configDirectory`, `<blade cacheDir>`) stay XML-only.
     */
    public const KEYS = [
        'modelProperties.columnFallback' => ['migrations', 'none'],
        'resolveDynamicWhereClauses' => self::BOOL,
        'resolveConfigReturnTypes' => self::BOOL,
        'reportImplicitQueryBuilderCalls' => self::BOOL,
        'findMissingTranslations' => self::BOOL,
        'findMissingViews' => self::BOOL,
        'findUnconfiguredFilesystemDisks' => self::BOOL,
        'findUnregisteredRouteNames' => self::BOOL,
        'findSerializedQueuedModels' => self::BOOL,
        'findOctaneIncompatibleBinding' => self::BOOL,
        'findPromptInjection' => self::BOOL,
        'blade' => self::BOOL,
        'blade.validateViewData' => self::BOOL,
        'blade.reportUnusedViewData' => self::BOOL,
        'experimental' => self::BOOL,
        'failOnInternalError' => self::BOOL,
    ];

    /** @param array<string, string> $values */
    private function __construct(private array $values) {}

    /**
     * Every token is validated, including ones a later repeat shadows, so a typo never hides.
     *
     * @param string $origin Names the source in error messages.
     *
     * @throws \InvalidArgumentException
     *
     * @psalm-pure
     */
    public static function parse(string $raw, string $origin = self::ENV_VAR): self
    {
        $values = [];

        \preg_match_all('/\S+/', $raw, $tokens);

        foreach ($tokens[0] as $token) {
            [$key, $value] = \explode('=', $token, 2) + [1 => null];

            if ($value === null) {
                throw new \InvalidArgumentException("{$origin}: '{$token}' is not KEY=VALUE.");
            }

            if (!isset(self::KEYS[$key])) {
                $supported = \implode(', ', \array_keys(self::KEYS));

                throw new \InvalidArgumentException("{$origin}: unknown key '{$key}'. Supported keys: {$supported}.");
            }

            if (!\in_array($value, self::KEYS[$key], true)) {
                $valid = \implode(', ', self::KEYS[$key]);

                throw new \InvalidArgumentException("{$origin}: invalid value '{$value}' for key '{$key}'. Valid values: {$valid}.");
            }

            $values[$key] = $value;
        }

        return new self($values);
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function bool(string $key): ?bool
    {
        return isset($this->values[$key]) ? $this->values[$key] === 'true' : null;
    }

    public function string(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }
}
