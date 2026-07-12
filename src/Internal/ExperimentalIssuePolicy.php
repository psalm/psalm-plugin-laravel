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
            // Psalm has already parsed issueHandlers when it invokes plugins. The
            // safe setter deliberately preserves a project's error level and any
            // scoped filters instead of replacing them with this default.
            $config->safeSetCustomErrorLevel($issueClass::getIssueType(), $level);
        }
    }
}
