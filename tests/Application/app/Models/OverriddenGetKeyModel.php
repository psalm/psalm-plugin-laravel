<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Model archetype whose getKey() behavior does not follow its primary-key metadata. */
class OverriddenGetKeyModel extends Model
{
    #[\Override]
    public function getKey(): string
    {
        return 'custom-key';
    }
}
