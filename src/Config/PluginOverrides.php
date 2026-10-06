<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Config;

/**
 * Per-run plugin setting overrides: validated `KEY=VALUE` tokens, typed by the {@see Setting} schema.
 *
 * Two layers exist, merged CLI over env by {@see self::over()}:
 *  - env: `PSALM_LARAVEL_OPTIONS`, whitespace-separated tokens (a value cannot contain whitespace);
 *  - CLI: `psalm-laravel analyze --plugin-option`, handed to the child psalm as a JSON list of tokens
 *    in a private variable, because `vendor/bin/psalm` rejects unknown flags and JSON keeps whitespace.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class PluginOverrides
{
    /** A real process variable, not a Laravel `.env` entry: it is read before the app boots. */
    public const ENV_VAR = 'PSALM_LARAVEL_OPTIONS';

    public const CLI_ENV_VAR = 'PSALM_LARAVEL_CLI_OPTIONS';

    /** @param array<string, bool|string|list<string>> $values */
    private function __construct(private array $values) {}

    /** @psalm-pure */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Every token is validated, including ones a later repeat shadows, so a typo never hides.
     * A repeated scalar key is last-wins; a list key accumulates within the layer.
     *
     * @param list<string> $tokens
     * @param string       $origin Names the source in error messages.
     *
     * @throws \InvalidArgumentException
     *
     * @psalm-pure
     */
    public static function parse(array $tokens, string $origin): self
    {
        $values = [];
        $lists = [];

        foreach ($tokens as $token) {
            [$key, $value] = \explode('=', $token, 2) + [1 => ''];

            if ($key === '' || $value === '') {
                throw new \InvalidArgumentException("{$origin}: token '{$token}' is invalid: expected KEY=VALUE with a non-empty value.");
            }

            $setting = Setting::find($key);

            if (!$setting instanceof Setting) {
                $supported = \implode(', ', \array_map(static fn(Setting $setting): string => $setting->key, Setting::all()));

                throw new \InvalidArgumentException("{$origin}: unknown key '{$key}'. Supported keys: {$supported}.");
            }

            if ($setting->values !== [] && !\in_array($value, $setting->values, true)) {
                $valid = \implode(', ', \array_map(static fn(string $valid): string => "'{$valid}'", $setting->values));

                throw new \InvalidArgumentException("{$origin}: invalid value '{$value}' for key '{$key}'. Valid values: {$valid}.");
            }

            if ($setting->type === SettingType::PathList) {
                $lists[$key][] = $value;
            } else {
                $values[$key] = $setting->type === SettingType::Bool ? $value === 'true' : $value;
            }
        }

        return new self($values + $lists);
    }

    /**
     * The user grammar of `PSALM_LARAVEL_OPTIONS`. A value starting with a double quote is rejected
     * so quoting can be added later without changing the meaning of existing values.
     *
     * @throws \InvalidArgumentException
     *
     * @psalm-pure
     */
    public static function fromEnv(?string $raw): self
    {
        $split = \preg_split('/\s+/', $raw ?? '', -1, \PREG_SPLIT_NO_EMPTY);
        $tokens = $split === false ? [] : $split;

        foreach ($tokens as $token) {
            if (\preg_match('/^[^=]*="/', $token) === 1) {
                throw new \InvalidArgumentException(
                    self::ENV_VAR . ": token '{$token}' starts its value with a double quote, which is reserved: values cannot contain whitespace here. "
                    . 'Set it in psalm.xml, or pass `psalm-laravel analyze --plugin-option`.',
                );
            }
        }

        return self::parse($tokens, self::ENV_VAR);
    }

    /**
     * Reads both layers from the process environment (pass `getenv()`); the one place env is touched.
     *
     * @param array<string, string> $env
     *
     * @throws \InvalidArgumentException
     */
    public static function fromEnvironment(array $env): self
    {
        $envLayer = self::fromEnv($env[self::ENV_VAR] ?? null);

        if (!isset($env[self::CLI_ENV_VAR])) {
            return $envLayer;
        }

        /** @psalm-var mixed $decoded Anything but a list of strings is rejected below. */
        $decoded = \json_decode($env[self::CLI_ENV_VAR], true);
        $tokens = [];

        foreach (\is_array($decoded) && \array_is_list($decoded) ? $decoded : [null] as $token) {
            if (!\is_string($token)) {
                throw new \InvalidArgumentException(self::CLI_ENV_VAR . ' is internal to `psalm-laravel analyze` and must hold a JSON list of KEY=VALUE strings.');
            }

            $tokens[] = $token;
        }

        return $envLayer->over(self::parse($tokens, '--plugin-option'));
    }

    /** Keys the higher layer sets replace the same keys here; a list is replaced wholesale, never merged. */
    public function over(self $higher): self
    {
        return new self(\array_replace($this->values, $higher->values));
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function bool(string $key): ?bool
    {
        $value = $this->values[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }

    public function string(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /** @return list<string>|null */
    public function list(string $key): ?array
    {
        $value = $this->values[$key] ?? null;

        return \is_array($value) ? $value : null;
    }
}
