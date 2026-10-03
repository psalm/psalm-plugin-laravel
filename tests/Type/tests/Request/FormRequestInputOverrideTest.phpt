--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regression for #1622: `InteractsWithInput::input()` must not be stubbed
 * `@psalm-mutation-free`. The real implementation goes through
 * `getInputSource()` -> `json()`, which lazily writes `$this->json`, so a
 * FormRequest that overrides `input()` (e.g. to sanitize) would otherwise
 * trip a false-positive ImmutableDependency (Psalm 7 enforces the parent's
 * mutation-free contract on overrides).
 */
final class Sanitizer
{
    public static function clean(mixed $value): string
    {
        return \is_string($value) ? \strip_tags($value) : '';
    }
}

final class SanitizingRequest extends FormRequest
{
    /** @inheritDoc */
    #[\Override]
    public function input($key = null, $default = null)
    {
        return Sanitizer::clean(parent::input($key, $default));
    }
}
?>
--EXPECTF--
