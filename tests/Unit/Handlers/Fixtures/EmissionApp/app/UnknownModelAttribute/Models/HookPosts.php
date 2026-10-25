<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Overrides the delegated `setForeignAttributesForCreate()` hook that `make()` / `create()` run on the
 * freshly filled related model.
 *
 * @extends HasMany<Post, Author>
 */
final class HookPosts extends HasMany
{
    #[\Override]
    protected function setForeignAttributesForCreate(Model $model)
    {
        if ($model->getAttribute('headline') !== null) {
            $model->setAttribute('title', $model->getAttribute('headline'));
        }

        parent::setForeignAttributesForCreate($model);
    }
}
