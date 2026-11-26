<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalScopesPost extends Model
{
    protected $table = 'posts';

    /**
     * Supplies a joining scope without registering it.
     *
     * @return array<class-string<JoinScope>, JoinScope>
     */
    #[\Override]
    public function getGlobalScopes(): array
    {
        return [JoinScope::class => new JoinScope()];
    }
}
