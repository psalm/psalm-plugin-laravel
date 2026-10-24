<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
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
 *  - `Mailer::send()` sets `$data['message']` AFTER merging everything else, for both, so that key
 *    always wins and is always the `Illuminate\Mail\Message`.
 *  - Livewire merges a component's public non-static properties into the view its `render()`
 *    returns, minus those its own base class declares.
 *
 * A property keeps its declared type (expanded against the class); an untyped or generic one is
 * `mixed`, which counts as supplied and is never type-checked. A key is never left out for lack of
 * a type: that would re-create the false positive.
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
     * @return array{0: array<string, Union>, 1: bool}
     */
    public static function forMailable(Codebase $codebase, string $receiverClass): array
    {
        $storage = self::storage($codebase, $receiverClass);
        $properties = $storage instanceof ClassLikeStorage
            ? self::publicProperties($codebase, $storage, self::MAILABLE, true)
            : null;

        // `+` keeps `message` on the left: the Mailer overwrites a property of that name.
        $keys = self::messageKey() + ($properties ?? []);

        if (!$storage instanceof ClassLikeStorage || $properties === null) {
            return [$keys, false];
        }

        $closed = !$storage->abstract
            && self::declaredOnlyBy($storage, ['buildviewdata', 'additionalmessagedata'], [Mailable::class]);

        return [$keys, $closed];
    }

    /**
     * @param array<string, Union> $passed what the call itself hands the template, which wins over a
     *                                     `toArray()` key of the same name
     *
     * @return array{0: array<string, Union>, 1: bool}
     */
    public static function forMailMessage(Codebase $codebase, string $receiverClass, array $passed): array
    {
        $keys = [];

        foreach (self::MAIL_MESSAGE_KEYS as $name) {
            $keys[$name] = Type::getMixed();
        }

        $keys['displayableActionUrl'] = Type::getString();
        $keys = \array_diff_key($keys, $passed) + self::messageKey();

        $storage = self::storage($codebase, $receiverClass);

        // `toArray()` lives on SimpleMessage; a subclass overriding either method can add names.
        $closed = $storage instanceof ClassLikeStorage
            && self::declaredOnlyBy($storage, ['toarray', 'data'], [SimpleMessage::class, MailMessage::class]);

        return [$keys, $closed];
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
     * @return array<string, Union>
     *
     * @psalm-pure
     */
    private static function messageKey(): array
    {
        return ['message' => new Union([new TNamedObject(Message::class)])];
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
            $properties[$name] = $property->type === null || $declaring->template_types !== null
                ? Type::getMixed()
                : TypeExpander::expandUnion(
                    $codebase,
                    $property->type,
                    $declaring->name,
                    $storage->name,
                    $declaring->parent_class,
                    final: $storage->final,
                );
        }

        return $properties;
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
