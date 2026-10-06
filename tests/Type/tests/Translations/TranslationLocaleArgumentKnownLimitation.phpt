--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION — an explicit `$locale` or `$fallback` still narrows against the booted
 * translator's default locale. A key that is a string there could in principle be a group (array)
 * in another locale; accepted because translation files share one structure across locales and a
 * missing key resolves to the key itself. Declining instead reported every
 * `'<p>' . trans('a.b', [], $locale)` on real projects. See the docblock on
 * `TranslationKeyHandler::resolveKeyArgReturnType()`.
 */

function seed_page(string $locale): string
{
    return '<div>' . trans('some.unresolvable.literal.key', [], $locale) . '</div>';
}

function section_label(string $locale): string
{
    return __('some.unresolvable.literal.key', [], $locale);
}

$_localePositional = app('translator')->get('some.unresolvable.literal.key', [], 'fr');
/** @psalm-check-type-exact $_localePositional = string */

$_localeNamed = app('translator')->get('some.unresolvable.literal.key', locale: 'fr');
/** @psalm-check-type-exact $_localeNamed = string */

$_fallbackNamed = app('translator')->get('some.unresolvable.literal.key', fallback: false);
/** @psalm-check-type-exact $_fallbackNamed = string */
?>
--EXPECTF--
