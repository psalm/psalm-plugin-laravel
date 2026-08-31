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
 */
final class ExperimentalIssuePolicy
{
    /** @var list<class-string<PluginIssue>> */
    private const ISSUES = [
        UnknownModelAttribute::class,
        UndefinedModelRelation::class,
    ];

    // Not marked mutation-free: it calls DefaultIssueLevels::apply(), which is not
    // mutation-free on Psalm 6 (see that class's docblock).
    public static function apply(bool $enforced): void
    {
        DefaultIssueLevels::apply(
            \array_map(
                static fn(string $issueClass): string => $issueClass::getIssueType(),
                self::ISSUES,
            ),
            $enforced ? Config::REPORT_ERROR : Config::REPORT_INFO,
        );
    }
}
