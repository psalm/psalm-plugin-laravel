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
 * Only for an issue whose HANDLER IS ALWAYS REGISTERED and needs a temporary severity discount
 * during its early-access period (see the lifecycle contract below). An issue whose registration
 * is itself gated by `<theFlag value="..." /> ?? <experimental>` —
 * {@see \Psalm\LaravelPlugin\Issues\MassAssignmentFromRequest},
 * {@see \Psalm\LaravelPlugin\Issues\SerializedQueuedModel} — does not belong here: once its handler
 * is registered, its findings are ordinary errors, and adding it to this list too would let an
 * explicit `<findMassAssignmentFromRequest value="true" />` with `<experimental>` left at its
 * `false` default silently downgrade the finding to `info`, which is not what "explicit override"
 * is supposed to mean.
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
        DefaultIssueLevels::apply(
            \array_map(
                static fn(string $issueClass): string => $issueClass::getIssueType(),
                self::ISSUES,
            ),
            $enforced ? Config::REPORT_ERROR : Config::REPORT_INFO,
        );
    }
}
