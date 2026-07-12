<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Config;
use Psalm\Config\IssueHandler;
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

    /**
     * Handlers installed by this policy, keyed weakly by the Psalm config they belong to.
     * Retaining the actual handler object lets a later plugin invocation distinguish its
     * own default from an explicit handler that the project owns.
     *
     * @var \WeakMap<Config, array<string, IssueHandler>>|null
     */
    private static ?\WeakMap $installedDefaults = null;

    /** @psalm-external-mutation-free */
    public static function apply(bool $enforced): void
    {
        $level = $enforced ? Config::REPORT_ERROR : Config::REPORT_INFO;
        $config = Config::getInstance();
        if (!self::$installedDefaults instanceof \WeakMap) {
            /** @var \WeakMap<Config, array<string, IssueHandler>> $installedDefaults */
            $installedDefaults = new \WeakMap();
            self::$installedDefaults = $installedDefaults;
        } else {
            $installedDefaults = self::$installedDefaults;
        }

        /** @var array<string, IssueHandler> $installedForConfig */
        $installedForConfig = isset($installedDefaults[$config]) ? $installedDefaults[$config] : [];

        foreach (self::ISSUES as $issueClass) {
            $issueType = $issueClass::getIssueType();
            $currentHandler = $config->getIssueHandlers()[$issueType] ?? null;

            if (
                isset($installedForConfig[$issueType])
                && $installedForConfig[$issueType] === $currentHandler
            ) {
                // A second Plugin invocation against the same Config must refresh
                // the default when `experimental` flips. Replacing the handler is
                // safe here because object identity proves this is still ours.
                $config->setCustomErrorLevel($issueType, $level);
                $installedForConfig[$issueType] = $config->getIssueHandlers()[$issueType];
                continue;
            }

            if ($currentHandler instanceof IssueHandler) {
                // Psalm parsed an explicit PluginIssue handler (or another caller
                // replaced ours). It owns both the base level and scoped filters.
                unset($installedForConfig[$issueType]);
                continue;
            }

            $config->setCustomErrorLevel($issueType, $level);
            $installedForConfig[$issueType] = $config->getIssueHandlers()[$issueType];
        }

        $installedDefaults[$config] = $installedForConfig;
    }
}
