<?php

declare(strict_types=1);

namespace RequestValidateThrowsFixture;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Request::validate() is a `@method` pseudo-method backed by Laravel's macro, which throws
 * ValidationException; with checkForThrowsDocblock an unhandled call must report MissingThrowsDocblock.
 * validateWithBag() is replaced in bootstrap/app.php, so it must stay silent.
 */
final class Callers
{
    /** @return array<string, mixed> */
    public static function uncaughtValidate(Request $request): array
    {
        return $request->validate(['name' => 'required']);
    }

    /** @return array<string, mixed> */
    public static function uncaughtValidateOnFormRequest(FormRequest $request): array
    {
        return $request->validate(['name' => 'required']);
    }

    /** @return array<string, mixed> */
    public static function replacedValidateWithBag(Request $request): array
    {
        return $request->validateWithBag('bag', ['name' => 'required']);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function declaredValidate(Request $request): array
    {
        return $request->validate(['name' => 'required']);
    }

    /** @return array<string, mixed> */
    public static function caughtValidate(Request $request): array
    {
        try {
            return $request->validate(['name' => 'required']);
        } catch (ValidationException) {
            return [];
        }
    }
}
