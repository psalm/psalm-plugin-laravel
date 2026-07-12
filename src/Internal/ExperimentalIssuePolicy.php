<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Config;
use Psalm\Issue\PluginIssue;
use Psalm\LaravelPlugin\Issues\UndefinedModelRelation;
use Psalm\LaravelPlugin\Issues\UnknownModelAttribute;

/**
 * Applies the default reporting policy for plugin diagnostics that are still
 * experimental. Individual projects can always override these defaults through
 * Psalm's normal issueHandlers configuration.
 *
 * @internal
 * @psalm-external-mutation-free
 */
final class ExperimentalIssuePolicy
{
    /** @var list<class-string<PluginIssue>> */
    private const ISSUES = [
        UnknownModelAttribute::class,
        UndefinedModelRelation::class,
    ];

    /** @psalm-external-mutation-free */
    public static function apply(bool $enforced): void
    {
        $level = $enforced ? Config::REPORT_ERROR : Config::REPORT_INFO;
        $config = Config::getInstance();

        foreach (self::ISSUES as $issueClass) {
            $issueType = $issueClass::getIssueType();
            $handlers = $config->getIssueHandlers();

            if (!isset($handlers[$issueType])) {
                // Psalm has already parsed issueHandlers when it invokes plugins.
                // The safe setter creates the plugin default without replacing an
                // explicit project handler.
                $config->safeSetCustomErrorLevel($issueType, $level);
                continue;
            }

            if (!self::hasExplicitDefaultLevel($config, $issueType)) {
                // A filter-only handler inherits Psalm's error default outside its
                // matching paths. Set the experimental default while retaining the
                // filter objects and their scoped levels.
                $handlers[$issueType]->setErrorLevel($level);
            }
        }
    }

    /**
     * @psalm-external-mutation-free
     * @psalm-suppress ImpureFunctionCall
     * @psalm-suppress ImpureMethodCall
     */
    private static function hasExplicitDefaultLevel(Config $config, string $issueType): bool
    {
        $path = $config->source_filename;
        if (!\is_string($path)) {
            // Configs created programmatically do not retain their XML. Preserve
            // their existing handler rather than risking an explicit override.
            return true;
        }

        $contents = \file_get_contents($path);
        if ($contents === false) {
            return true;
        }

        $previous = \libxml_use_internal_errors(true);
        $xml = \simplexml_load_string($contents);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if (!$xml instanceof \SimpleXMLElement) {
            return true;
        }

        $issueHandlers = $xml->issueHandlers;
        if (!$issueHandlers instanceof \SimpleXMLElement) {
            return false;
        }

        $pluginIssues = $issueHandlers->PluginIssue;
        if (!$pluginIssues instanceof \SimpleXMLElement) {
            return false;
        }

        /** @psalm-var iterable<\SimpleXMLElement> $pluginIssues */
        foreach ($pluginIssues as $handler) {
            if ((string) $handler['name'] === $issueType) {
                return isset($handler['errorLevel']);
            }
        }

        return false;
    }
}
