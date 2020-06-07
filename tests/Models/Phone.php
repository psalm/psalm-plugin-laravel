<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Phone extends Model {
    protected $table = 'phone_numbers';

    /**
     * @psalm-return BelongsTo<User>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
