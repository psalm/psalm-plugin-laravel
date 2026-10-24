<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Mail\TextMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SimpleMessage;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * The keys Laravel (and Livewire) merge into a view around the call site that names it, for the
 * three receivers {@see ComponentRenderData} does not cover. Without them every template declaring
 * one is a {@see \Psalm\LaravelPlugin\Issues\MissingViewData} false positive.
 *
 * Read off `vendor/laravel/framework`:
 *  - `Mailable::buildViewData()` adds every public property that is not declared by `Mailable`
 *    itself (reflection `IS_PUBLIC`, so statics too, and the ones a subclass or its traits declare).
 *  - `MailMessage::data()` is `toArray() + viewData`: the call's own data wins over a `toArray()`
 *    key of the same name.
 *  - `Mailer::send()` sets `$data['message']` (the `Illuminate\Mail\Message`) AFTER merging everything
 *    else, so on the `view()` path that key always wins. The markdown renderers merge the other way,
 *    `array_merge($data, $viewData)` (`Mailable::buildMarkdownHtml()`, `MailChannel::buildMarkdownHtml()`),
 *    so on the `markdown()` path a property or passed key named `message` wins. A Mailable's text
 *    pass (`Mailable::buildMarkdownText()`) hands the template `TextMessage` instead, so `message` is
 *    `Message|TextMessage` there; `MailChannel` renders a notification once, with the `Message`.
 *  - Livewire merges a component's public non-static properties into the view its `render()`
 *    returns, minus those its own base class declares.
 *
 * A property keeps its declared type, expanded against the class it appears in (`self` in a trait
 * is the class using it; `static` is the rendered class); an untyped or generic one is `mixed`, which
 * counts as supplied and is never type-checked. A key is never left out for lack of a type: that
 * would re-create the false positive.
 *
 * A key's type is given up (`mixed`, still supplied) wherever the runtime value may differ from the
 * one the plugin can read: every key when a userland override of an extraction method can return
 * anything for it, and the key the call's own data wins over (`displayableActionUrl`, and `message`
 * on the `markdown()` path) when that data is not fully readable.
 *
 * Each resolver answers `[keys, complete]`. `complete` is false when something the plugin cannot
 * enumerate may add more names: an abstract receiver (a subclass declares more properties), a
 * userland override of an extraction method, or a class Psalm cannot resolve. The keys then still
 * count as supplied; only the report of a missing name is withheld.
 *
 * Not modelled: the static `Mailable::buildViewDataUsing()` callback, properties assigned outside
 * the declaration (a dynamic property), and anything Livewire adds at runtime through a trait.
 *
 * @internal
 */
final class FrameworkViewData
{
    private const MAILABLE = 'illuminate\mail\mailable';

    private const LIVEWIRE_COMPONENT = 'livewire\component';

    /** `MailMessage::toArray()`, minus the one key whose type is known. */
    private const MAIL_MESSAGE_KEYS = [
        'level', 'subject', 'greeting', 'salutation', 'introLines', 'outroLines', 'actionText', 'actionUrl',
    ];

    /**
     * @param array<string, Union> $passed      what the call itself hands the template
     * @param bool                 $markdown    the `markdown()` binder rather than `view()`
     * @param bool                 $passedKnown the call's data is fully readable, so no unknown key of it
     *                                          can replace a framework value the call's data wins over
     *
     * @return array{0: array<string, Union>, 1: bool}
     */
    public static function forMailable(Codebase $codebase, string $receiverClass, array $passed, bool $markdown, bool $passedKnown): array
    {
        $storage = self::storage($codebase, $receiverClass);
        $properties = $storage instanceof ClassLikeStorage
            ? self::publicProperties($codebase, $storage, self::MAILABLE, true)
            : null;

        if (!$storage instanceof ClassLikeStorage || $properties === null) {
            return [self::withMessageKey([], $passed, $markdown, self::markdownMessage(false, true)), false];
        }

        // A userland override returns any value for any key, so a property type no longer says what
        // the template gets. Only the view path's `message` survives: the Mailer sets it afterwards.
        $untouched = self::declaredOnlyBy($storage, ['buildviewdata', 'additionalmessagedata'], [Mailable::class]);
        $keys = self::withMessageKey(
            $untouched ? $properties : self::asMixed($properties),
            $passed,
            $markdown,
            self::markdownMessage($untouched && $passedKnown, true),
        );

        return [$keys, $untouched && !$storage->abstract];
    }

    /**
     * @param array<string, Union> $passed      what the call itself hands the template, which wins over a
     *                                          `toArray()` key of the same name
     * @param bool                 $markdown    the `markdown()` binder rather than `view()`
     * @param bool                 $passedKnown the call's data is fully readable, so no unknown key of it
     *                                          can replace a framework value the call's data wins over
     *
     * @return array{0: array<string, Union>, 1: bool}
     */
    public static function forMailMessage(Codebase $codebase, string $receiverClass, array $passed, bool $markdown, bool $passedKnown): array
    {
        $storage = self::storage($codebase, $receiverClass);

        // `toArray()` lives on SimpleMessage; a subclass overriding either method can add names, and
        // return any value for the ones the framework supplies.
        $untouched = $storage instanceof ClassLikeStorage
            && self::declaredOnlyBy($storage, ['toarray', 'data'], [SimpleMessage::class, MailMessage::class]);

        $keys = [];

        foreach (self::MAIL_MESSAGE_KEYS as $name) {
            $keys[$name] = Type::getMixed();
        }

        $keys['displayableActionUrl'] = $untouched && $passedKnown ? Type::getString() : Type::getMixed();
        $keys = self::withMessageKey(\array_diff_key($keys, $passed), $passed, $markdown, self::markdownMessage($untouched && $passedKnown, false));

        return [$keys, $untouched];
    }

    /**
     * The properties Livewire hands the view `render()` returns, for the class being analyzed, or
     * null when it is not a Livewire component (including whenever Livewire is not installed: Psalm
     * has no `Livewire\Component` storage to find among the ancestors then).
     *
     * @return array{0: array<string, Union>, 1: bool}|null
     */
    public static function forLivewire(StatementsSource $source): ?array
    {
        $fqClassName = $source->getFQCLN();

        if ($fqClassName === null) {
            return null;
        }

        $codebase = $source->getCodebase();
        $storage = self::storage($codebase, $fqClassName);

        if (!$storage instanceof ClassLikeStorage || !isset($storage->parent_classes[self::LIVEWIRE_COMPONENT])) {
            return null;
        }

        $properties = self::publicProperties($codebase, $storage, self::LIVEWIRE_COMPONENT, false);

        return $properties === null ? [[], false] : [$properties, !$storage->abstract];
    }

    /**
     * Adds the Mailer's `$message`, on the side of the merge Laravel puts it: last on the `view()` path
     * (it overwrites a property, a passed key and anything an override of an extraction method
     * returns), first on the `markdown()` path (anything that names `message` overwrites it).
     *
     * @param array<string, Union> $keys
     * @param array<string, Union> $passed
     * @param Union                $markdownMessage what the template gets when nothing else names `message`
     *
     * @return array<string, Union>
     *
     * @psalm-pure
     */
    private static function withMessageKey(array $keys, array $passed, bool $markdown, Union $markdownMessage): array
    {
        if (!$markdown) {
            return ['message' => new Union([new TNamedObject(Message::class)])] + $keys;
        }

        return isset($passed['message']) ? $keys : $keys + ['message' => $markdownMessage];
    }

    /**
     * The `message` a markdown template gets when no property or passed key names it, or `mixed`
     * when something the plugin cannot read may. A Mailable's text pass wraps it in `TextMessage`
     * (`Mailable::buildMarkdownText()`); `MailChannel` renders a notification once, with the `Message`.
     *
     * @param bool $known    nothing unreadable can name `message`
     * @param bool $mailable the Mailable renderer rather than `MailChannel`'s
     *
     * @psalm-pure
     */
    private static function markdownMessage(bool $known, bool $mailable): Union
    {
        if (!$known) {
            return Type::getMixed();
        }

        $atomics = [new TNamedObject(Message::class)];

        if ($mailable) {
            $atomics[] = new TNamedObject(TextMessage::class);
        }

        return new Union($atomics);
    }

    /**
     * Whether every method named is declared by one of the framework classes, i.e. never overridden.
     *
     * @param list<lowercase-string>  $methodNames
     * @param list<class-string>      $frameworkClasses
     *
     * @psalm-mutation-free
     */
    private static function declaredOnlyBy(ClassLikeStorage $storage, array $methodNames, array $frameworkClasses): bool
    {
        $allowed = \array_map(\strtolower(...), $frameworkClasses);

        foreach ($methodNames as $methodName) {
            $declaring = $storage->declaring_method_ids[$methodName] ?? null;

            if (!$declaring instanceof MethodIdentifier || !\in_array(\strtolower($declaring->fq_class_name), $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every public property of the class that a base class does not declare, typed as it is declared.
     *
     * @param lowercase-string $excludedDeclarer the framework base class whose own properties are not data
     *
     * @return array<string, Union>|null null when a declaring class could not be resolved, which
     *         makes the set unknowable rather than empty
     */
    private static function publicProperties(
        Codebase $codebase,
        ClassLikeStorage $storage,
        string $excludedDeclarer,
        bool $withStatics,
    ): ?array {
        $properties = [];

        foreach ($storage->declaring_property_ids as $name => $declaringClass) {
            if (\strtolower($declaringClass) === $excludedDeclarer) {
                continue;
            }

            $declaring = self::storage($codebase, $declaringClass);

            if (!$declaring instanceof ClassLikeStorage) {
                return null;
            }

            $property = $declaring->properties[$name] ?? null;

            if ($property === null
                || $property->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC
                || ($property->is_static === true && !$withStatics)
            ) {
                continue;
            }

            // A generic declaring class has no template bound here, so its property type would be a guess.
            // `self` and `parent` name the class the property appears in: Psalm records a trait as the
            // declaring class, but `self` there is the class that uses the trait. Only `static` is the
            // rendered class.
            $appearing = self::storage($codebase, self::appearingClass($storage, $name, $declaringClass));

            $properties[$name] = $property->type === null || $declaring->template_types !== null || !$appearing instanceof ClassLikeStorage
                ? Type::getMixed()
                : TypeExpander::expandUnion(
                    $codebase,
                    $property->type,
                    $appearing->name,
                    $storage->name,
                    $appearing->parent_class,
                    final: $storage->final,
                );
        }

        return $properties;
    }

    /**
     * The class the property appears in, from Psalm's `Class::$property` appearing id: the class that
     * uses the trait for a trait property, the declaring class otherwise.
     *
     * @psalm-mutation-free
     */
    private static function appearingClass(ClassLikeStorage $storage, string $propertyName, string $declaringClass): string
    {
        $appearingId = $storage->appearing_property_ids[$propertyName] ?? null;
        $separator = $appearingId === null ? false : \strpos($appearingId, '::$');

        return $appearingId === null || $separator === false ? $declaringClass : \substr($appearingId, 0, $separator);
    }

    /**
     * Every key of the set typed `mixed`: the keys stay supplied, only the type is given up.
     *
     * @param array<string, Union> $keys
     *
     * @return array<string, Union>
     *
     * @psalm-pure
     */
    private static function asMixed(array $keys): array
    {
        $mixed = [];

        foreach (\array_keys($keys) as $name) {
            $mixed[$name] = Type::getMixed();
        }

        return $mixed;
    }

    /**
     * @psalm-mutation-free
     */
    private static function storage(Codebase $codebase, string $fqClassName): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get(\strtolower($fqClassName));
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
    }
}
