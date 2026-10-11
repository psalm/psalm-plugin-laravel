<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

// `build` runs ComposesCount::compose, but Psalm maps the alias onto the other trait's body.
final class AliasConflictComposer
{
    use ComposesTitle, ComposesCount {
        ComposesTitle::compose insteadof ComposesCount;
        ComposesCount::compose as build;
    }
}
