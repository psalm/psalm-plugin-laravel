<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\ImplicitQueryBuilderCallNewQueryModel;
use PsalterFixture\Models\ImplicitQueryBuilderCallQueryModel;

/**
 * `Model::__call()` forwards through `$this->newQuery()` and `Model::query()` is `(new static)->newQuery()`, so an
 * overridden `newQuery()` runs the same before and after the rewrite: rewritten. A `query()` override is on the
 * static path only, so an instance call on that model is rewritten as well.
 */
final class NewQueryOverride
{
    public function run(ImplicitQueryBuilderCallNewQueryModel $model, ImplicitQueryBuilderCallQueryModel $queryModel): void
    {
        ImplicitQueryBuilderCallNewQueryModel::query()->where('tenant_id', 99)->get();
        $model->newQuery()->where('tenant_id', 99)->get();
        $queryModel->newQuery()->where('tenant_id', 99)->get();
    }
}
