<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when an Eloquent mass-assignment method (`create()` / `fill()` / `update()`, statically,
 * on an instance, or through a `Builder`/`Relation` forwarding form) is passed an argument whose
 * provenance proves it is raw, unfiltered request data — `$request->all()`, `request()->all()`, the
 * `query`/`request` `InputBag` properties, or `json()`, each read directly or through one local
 * variable assignment. An attacker who controls the request body can inject any column this way,
 * including ones the form never exposed (the classic privilege-escalation mass-assignment hole).
 *
 * Opt-in only: emitted exclusively when `<findMassAssignmentFromRequest value="true" />` is set on
 * the `<pluginClass>` element in psalm.xml, or `<experimental value="true" />` is set with no
 * explicit override.
 */
final class MassAssignmentFromRequest extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/MassAssignmentFromRequest/';
}
