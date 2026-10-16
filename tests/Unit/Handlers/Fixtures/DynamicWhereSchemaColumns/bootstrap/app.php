<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), see the
// UnknownModelAttribute fixture for why Testbench's package-mode fallback cannot be used.
$app = Application::configure(basePath: \dirname(__DIR__))->create();

// Builder::__call dispatches macros before any query forwarding, so this must win over the
// schema-derived dynamic where for the `tag_id` column. The string param and return deliberately
// differ from the column's integer type.
Builder::macro('whereTagId', static fn(string $tag): string => $tag);

return $app;
