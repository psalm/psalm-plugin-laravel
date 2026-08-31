<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Config;
use Psalm\Config\IssueHandler;

/**
 * Installs plugin-chosen default reporting levels for issue types the project
 * has not configured itself.
 *
 * Psalm has no "default unless overridden" setter, so the safe behavior is
 * built here: an issue the project already has a handler for is left alone
 * completely (that handler owns both its base level and its scoped filters),
 * and a default this class installed earlier is recognized by object identity
 * so a second plugin invocation can refresh it when the governing flag flips.
 *
 * @internal
 */
final class DefaultIssueLevels
{
    /**
     * Handlers installed by this class, keyed weakly by the Psalm config they belong to.
     * Retaining the actual handler object lets a later plugin invocation distinguish its
     * own default from an explicit handler that the project owns.
     *
     * @var \WeakMap<Config, array<string, IssueHandler>>|null
     */
    private static ?\WeakMap $installedDefaults = null;

    /**
     * @param list<string> $issueTypes
     * @param Config::REPORT_* $level
     *
     * Not marked mutation-free: Psalm 6's WeakMap::offsetGet()/offsetSet() and
     * Config::setCustomErrorLevel() are not annotated mutation-free, unlike Psalm 7.
     */
    public static function apply(array $issueTypes, string $level): void
    {
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

        foreach ($issueTypes as $issueType) {
            $currentHandler = $config->getIssueHandlers()[$issueType] ?? null;

            if (
                isset($installedForConfig[$issueType])
                && $installedForConfig[$issueType] === $currentHandler
            ) {
                // A second Plugin invocation against the same Config must refresh
                // the default when the governing flag flips. Replacing the handler
                // is safe here because object identity proves this is still ours.
                $config->setCustomErrorLevel($issueType, $level);
                $installedForConfig[$issueType] = $config->getIssueHandlers()[$issueType];
                continue;
            }

            if ($currentHandler instanceof IssueHandler) {
                // Psalm parsed an explicit handler for this issue (or another caller
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
