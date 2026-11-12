<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\ImplicitQueryBuilderCallMagicCallModel;
use PsalterFixture\Models\ImplicitQueryBuilderCallQueryModel;
use PsalterFixture\Models\ImplicitQueryBuilderCallStaticMagicModel;

/**
 * A model (or one of its non-Model ancestors or traits) that overrides `__call`, `__callStatic` or `query` changes
 * what a forwarded call does, and an inserted `query()->` / `newQuery()->` would skip that code: these calls are
 * reported and never rewritten. (`newQuery()` is on both paths: see NewQueryOverride.)
 */
final class MagicDispatchOverride
{
    public function run(ImplicitQueryBuilderCallMagicCallModel $model): void
    {
        ImplicitQueryBuilderCallMagicCallModel::where('tenant_id', 99)->get();
        $model->where('tenant_id', 99)->get();
        ImplicitQueryBuilderCallStaticMagicModel::where('tenant_id', 99)->get();
        ImplicitQueryBuilderCallQueryModel::where('tenant_id', 99)->get();
    }
}
