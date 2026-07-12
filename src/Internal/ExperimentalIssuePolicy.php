<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Config;

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
    /** @var list<non-empty-string> */
    private const ISSUE_TYPES = [
        'UnknownModelAttribute',
        'UndefinedModelRelation',
    ];

    /** @psalm-external-mutation-free */
    public static function apply(bool $enforced): void
    {
        $level = $enforced ? Config::REPORT_ERROR : Config::REPORT_INFO;
        $config = Config::getInstance();

        foreach (self::ISSUE_TYPES as $issueType) {
            // Psalm has already parsed issueHandlers when it invokes plugins. The
            // safe setter deliberately preserves a project's error level and any
            // scoped filters instead of replacing them with this default.
            $config->safeSetCustomErrorLevel($issueType, $level);
        }
    }
}
