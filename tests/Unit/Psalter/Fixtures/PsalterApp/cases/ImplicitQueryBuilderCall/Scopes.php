<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\ImplicitQueryBuilderCallArticle;

final class Scopes
{
    public function run(): void
    {
        ImplicitQueryBuilderCallArticle::active()->get();
        ImplicitQueryBuilderCallArticle::published()->get();
    }
}
