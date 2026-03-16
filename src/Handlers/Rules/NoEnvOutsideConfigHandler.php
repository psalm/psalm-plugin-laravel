<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\NoEnvOutsideConfig;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type\Union;

/**
 * Reports env() calls outside the config/ directory.
 *
 * When config is cached (php artisan config:cache), the .env file is not loaded,
 * so env() returns null outside config files. Test files are excluded because
 * they run without config caching.
 *
 * @see https://laravel.com/docs/configuration#configuration-caching
 */
final class NoEnvOutsideConfigHandler implements FunctionReturnTypeProviderInterface
{
    private static string $configPath = '';

    /** @psalm-external-mutation-free */
    public static function init(string $configPath): void
    {
        self::$configPath = \rtrim($configPath, \DIRECTORY_SEPARATOR);
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['env'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $filePath = $event->getStatementsSource()->getFilePath();

        if (self::isInsideConfigDirectory($filePath) || self::isTestFile($filePath)) {
            return null;
        }

        IssueBuffer::accepts(
            new NoEnvOutsideConfig(
                'env() called outside config directory. '
                    . 'When config is cached, env() returns null. Use config() instead.',
                $event->getCodeLocation(),
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /** @psalm-external-mutation-free */
    private static function isInsideConfigDirectory(string $filePath): bool
    {
        if (self::$configPath === '') {
            return false;
        }

        return \str_starts_with($filePath, self::$configPath . \DIRECTORY_SEPARATOR)
            || $filePath === self::$configPath;
    }

    /** @psalm-pure */
    private static function isTestFile(string $filePath): bool
    {
        return \str_contains($filePath, \DIRECTORY_SEPARATOR . 'tests' . \DIRECTORY_SEPARATOR);
    }
}
