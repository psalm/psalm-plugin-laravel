<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post;

/**
 * A trait body is edited once however many classes use it, and a consumer that is not a Post would approve an
 * edit that is wrong for a consumer that is. A call in a trait is reported and never rewritten.
 */
trait TraitHostedBuilder
{
    public function build(): Post
    {
        return Post::make(['title' => 'x']);
    }
}

final class TraitHostedHelper
{
    use TraitHostedBuilder;
}
