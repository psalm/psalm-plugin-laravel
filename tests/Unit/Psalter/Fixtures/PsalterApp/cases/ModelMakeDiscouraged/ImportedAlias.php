<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post as Article;

final class ImportedAlias
{
    public function alias(): Article
    {
        return Article::make();
    }

    public function fullyQualified(): \PsalterFixture\Models\Post
    {
        return \PsalterFixture\Models\Post::make();
    }
}
