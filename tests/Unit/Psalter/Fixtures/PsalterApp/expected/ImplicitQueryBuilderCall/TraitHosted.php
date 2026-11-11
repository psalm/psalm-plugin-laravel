<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

/**
 * A trait body is edited once however many classes use it, and a consumer that is not a Post would approve an
 * edit that is wrong for a consumer that is. A call in a trait is reported and never rewritten.
 */
trait TraitHostedQueries
{
    public function run(): void
    {
        Post::where('title', 'x')->get();
    }
}

final class TraitHostedHelper
{
    use TraitHostedQueries;
}
