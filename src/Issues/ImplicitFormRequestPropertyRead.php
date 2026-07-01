<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when an undeclared field is read as a magic property on a FormRequest subclass
 * (`$this->email`, `$request->email`) instead of through an explicit validated-input accessor
 * (`$this->validated('email')`, `$request->safe()->email`, `$request->input('email')`).
 *
 * Such a read resolves through Laravel's `Request::__get`, which reads the **raw** input bag
 * (falling back to a route parameter only when the field is absent from input), bypassing the
 * `validated()` / `safe()` contract even on a validated request. The plugin
 * already narrows these reads to the field's validation-rule type (#1022); this opt-in rule
 * additionally flags them so teams can require the explicit accessor, the FormRequest counterpart
 * of {@see ImplicitQueryBuilderCall} (which forbids Laravel's `__callStatic` / `__call` magic
 * forwarding on models).
 *
 * Opt-in only: emitted exclusively when `<reportImplicitFormRequestPropertyReads value="true" />`
 * is set on the `<pluginClass>` element in psalm.xml.
 */
final class ImplicitFormRequestPropertyRead extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/ImplicitFormRequestPropertyRead/';
}
