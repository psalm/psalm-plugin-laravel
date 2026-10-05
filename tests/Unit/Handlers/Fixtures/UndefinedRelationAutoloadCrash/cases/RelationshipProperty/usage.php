<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\RelationshipProperty;

function drive_relationship_properties(RelationshipPropertyModel $model): void
{
    $price = $model->price;
    $tag = $model->tag;
}
