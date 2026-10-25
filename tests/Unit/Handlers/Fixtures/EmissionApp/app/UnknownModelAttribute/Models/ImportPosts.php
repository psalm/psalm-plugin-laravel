<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relation subclass overriding `createMany()` (userland key mapping); the inherited
 * `createManyQuietly()` goes through it.
 *
 * @extends HasMany<Post, Author>
 */
final class ImportPosts extends HasMany
{
    /** @param iterable<array<string, mixed>> $records */
    #[\Override]
    public function createMany(iterable $records)
    {
        $mapped = [];

        foreach ($records as $record) {
            if (isset($record['headline'])) {
                $record['title'] = $record['headline'];
                unset($record['headline']);
            }

            $mapped[] = $record;
        }

        return parent::createMany($mapped);
    }
}
