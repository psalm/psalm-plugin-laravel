<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post as Article;

final class ImportedAlias
{
    public function alias(): Article
    {
        return (new Article());
    }

    public function fullyQualified(): \PsalterFixture\Models\Post
    {
        return (new \PsalterFixture\Models\Post());
    }
}
