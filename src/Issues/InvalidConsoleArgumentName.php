<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when $command->argument('x') references a name not defined
 * in the command's $signature / InputDefinition.
 */
final class InvalidConsoleArgumentName extends PluginIssue {}
