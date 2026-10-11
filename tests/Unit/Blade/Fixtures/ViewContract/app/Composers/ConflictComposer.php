<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

// Psalm ignores `insteadof` (vimeo/psalm#12113), so it cannot tell which trait body wins.
final class ConflictComposer
{
    use ComposesTitle, ComposesCount {
        ComposesTitle::compose insteadof ComposesCount;
    }
}
