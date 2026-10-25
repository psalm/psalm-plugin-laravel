<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Application;

// A real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), not the Testbench
// package-mode fallback (branch 3). Only this boot path reads this directory's database/migrations
// (database_path()), config/filesystems.php (arms UnconfiguredFilesystemDisk with these disks) and,
// through withRouting(), routes/web.php (the named-route table). The Testbench branch anchors all of
// them at its bundled skeleton regardless of the analysed project (see
// ApplicationProvider::retargetConfigPathAtProjectRoot()'s docblock).
$app = Application::configure(basePath: \dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php')
    ->create();

// DynamicWhereSchemaColumns: Builder::__call dispatches macros before any query forwarding, so this must
// win over the schema-derived dynamic where for the `tag_id` column. The string param and return
// deliberately differ from the column's integer type.
Builder::macro('whereTagId', static fn(string $tag): string => $tag);

// Relation::__call consults Relation macros only on relation receivers, so this must not veto the
// direct model forms; Query\Builder macros never change the Eloquent wrapper's return value.
Relation::macro('whereFilterType', static fn(string $type): string => $type);
QueryBuilder::macro('whereOwnerId', static fn(int $id): string => (string) $id);
// Its argument type differs from the integer `slug`-like column: the macro's args are not column values.
QueryBuilder::macro('whereSlug', static fn(array $slugs): string => (string) \count($slugs));

return $app;
