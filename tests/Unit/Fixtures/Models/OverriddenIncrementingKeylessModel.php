<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A custom getCasts() delegates to the framework implementation while a virtual incrementing getter
 * keeps the null-key conflict active. The fixture installs Psalm's error-promotion behavior locally and
 * replays PHP 8.5's framework deprecation on older PHP versions, producing the RuntimeException that
 * production analysis sees deterministically across every supported runtime.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class OverriddenIncrementingKeylessModel extends Model
{
    protected $primaryKey;

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
    ];

    #[\Override]
    public function getIncrementing(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    #[\Override]
    public function getCasts(): array
    {
        $promoteError = static function (int $severity, string $message, string $file, int $line): never {
            throw new \RuntimeException(
                "PHP Error: {$message} in {$file}:{$line} for command with CLI args \"--no-progress\"",
                $severity,
            );
        };

        \set_error_handler($promoteError);

        try {
            parent::getCasts();

            // PHP 8.5 deprecates using null as an array key and invokes the handler above. Earlier
            // supported PHP versions silently coerce it to an empty-string key, so replay that same
            // framework-origin diagnostic when the parent call returns to keep this fixture testing
            // recovery rather than PHP's version-specific error-reporting behavior.
            $frameworkMethod = new \ReflectionMethod(Model::class, 'getCasts');
            $frameworkFile = $frameworkMethod->getFileName();
            $frameworkLine = $frameworkMethod->getStartLine();
            if (!\is_string($frameworkFile) || !\is_int($frameworkLine)) {
                throw new \LogicException('Could not locate Eloquent Model::getCasts().');
            }

            $promoteError(
                \E_DEPRECATED,
                'Using null as an array offset is deprecated, use an empty string instead',
                $frameworkFile,
                $frameworkLine,
            );
        } finally {
            \restore_error_handler();
        }
    }
}
