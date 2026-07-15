<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Casts\InboundOnlyCast;

/** @internal fixture used by ModelMetadataRegistryTest */
final class SectionFailureModel extends Model
{
    use HasUuids;

    /** @var array<string, true> */
    public static array $failures = [];

    protected $table = 'section_failure_models';

    /** @var array<string, string> */
    protected $casts = [
        'flag' => 'boolean',
        'code' => InboundOnlyCast::class,
    ];

    public function usesTimestamps(): bool
    {
        $this->fail('runtime configuration');

        return parent::usesTimestamps();
    }

    public function getTable(): string
    {
        $this->fail('schema');

        return parent::getTable();
    }

    /** @return array<string, string> */
    public function getCasts(): array
    {
        $this->fail('casts');

        return parent::getCasts();
    }

    private function fail(string $section): void
    {
        if (isset(self::$failures[$section])) {
            throw new \RuntimeException("deliberate {$section} failure");
        }
    }
}
