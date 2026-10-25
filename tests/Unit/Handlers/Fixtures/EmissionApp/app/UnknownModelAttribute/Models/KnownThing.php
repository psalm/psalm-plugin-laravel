<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model with a real migration-backed schema (`known_things`: name, email). Exercises
 * {@see \Psalm\LaravelPlugin\Handlers\Rules\UnknownModelAttributeHandler} end-to-end, including the
 * `static::create()` / `self::create()` receiver forms used idiomatically inside a model's own
 * methods: Psalm's name resolver does not rewrite `self`/`static` to an FQCN, so the handler must
 * resolve them against the enclosing class explicitly or these forms silently bypass the rule.
 */
class KnownThing extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'email'];

    protected $table = 'known_things';

    /** Idiomatic `static::create()` from a static factory method — must flag the typo'd key. */
    public static function makeViaStaticTypo(): self
    {
        return static::create(['nmae' => 'Ada', 'email' => 'a@b.com']);
    }

    /** Idiomatic `self::create()` from an instance method — must flag the typo'd key. */
    public function makeViaSelfTypo(): self
    {
        return self::create(['name' => 'Ada', 'unknown_col' => 'x']);
    }

    /** A clean call through both receiver forms must never be flagged. */
    public static function makeClean(): self
    {
        return static::create(['name' => 'Ada', 'email' => 'a@b.com']);
    }
}
