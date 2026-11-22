<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Overrides the named-scope dispatch to route `orderBy()` to a joining method. */
final class ScopeAliasPost extends Model
{
    protected $table = 'posts';

    #[\Override]
    public function hasNamedScope($scope)
    {
        return $scope === 'orderBy' || parent::hasNamedScope($scope);
    }

    #[\Override]
    public function callNamedScope($scope, array $parameters = [])
    {
        if ($scope !== 'orderBy') {
            return parent::callNamedScope($scope, $parameters);
        }

        /** @var Builder<static> $query */
        $query = $parameters[0];

        return $query->join('authors', 'authors.id', '=', 'posts.author_id');
    }
}
