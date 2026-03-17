<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when argument('x') or option('x') references a name
 * not defined in the command's $signature / InputDefinition.
 */
final class UndefinedConsoleInput extends PluginIssue {}
