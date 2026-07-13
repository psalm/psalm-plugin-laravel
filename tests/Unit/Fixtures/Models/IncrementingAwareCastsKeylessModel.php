<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A custom getCasts() override that observes effective getter state. The registry must report the
 * invalid key/incrementing combination without mutating the reflection-only instance.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class IncrementingAwareCastsKeylessModel extends Model
{
    protected $primaryKey;

    /** @return array<string, string> */
    public function getCasts()
    {
        return [
            'stateful_value' => $this->getIncrementing() ? 'integer' : 'string',
        ];
    }
}
