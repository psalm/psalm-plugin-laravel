<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\Mail\Message;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\PropertyStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * The keys Laravel and Livewire add to a template's data at render time, for the three receivers
 * {@see ComponentRenderData} does not cover. Each lands in {@see ViewCallChain::$frameworkData}:
 * supplied, never "passed", so `UnusedViewData` cannot report them.
 *
 * A property whose type is not declared, or is declared by a generic class, is `mixed` (exempt from
 * `InvalidViewData`) rather than a guess. Every result is `[keys, complete]`, `complete` being false
 * when the key set cannot be enumerated statically.
 *
 * Mailable: `Mailable::buildViewData()` merges `$viewData` with every initialized public property
 * whose declaring class is not `Mailable` itself, statics included
 * (`ReflectionClass::getProperties(IS_PUBLIC)`), so a property overrides a `with()` key. An
 * uninitialized typed property still counts as supplied: a superset only ever silences a report.
 *
 * MailMessage: `MailMessage::data()` is `array_merge(toArray(), viewData)`, so a key the call passes
 * wins over the `toArray()` one.
 *
 * Mailer: `Mailer::send()` adds `message`. `view()` and `markdown()` order that against the data
 * differently, so a call that also supplies a `message` makes the key `mixed`.
 *
 * Livewire: `HandleComponents::getView()` renders `render()`'s view with
 * `Utils::getPublicPropertiesDefinedOnSubclass()` (livewire/livewire src/Mechanisms/HandleComponents/
 * HandleComponents.php, src/Drawer/BaseUtils.php): public, non-static properties whose declaring
 * class is neither `Livewire\Component` nor `Livewire\Volt\Component`. `generateBladeView()` then calls
 * `->with($properties)` on the view `render()` returned, so a property wins over a passed key.
 *
 * A subclass can add public properties no static read of the receiver's declared type sees; that,
 * `Mailable::buildViewDataUsing()` and `new Content(with: ...)` are accepted imprecisions.
 *
 * @internal
 */
final class FrameworkViewData
{
    private const MAILABLE = 'illuminate\mail\mailable';

    private const MAIL_MESSAGE = 'illuminate\notifications\messages\mailmessage';

    private const SIMPLE_MESSAGE = 'illuminate\notifications\messages\simplemessage';

    private const LIVEWIRE = 'livewire\component';

    /** Declaring classes whose own properties Livewire never exposes. */
    private const LIVEWIRE_BASES = [self::LIVEWIRE, 'livewire\volt\component'];

    /** `SimpleMessage::toArray()`, minus `displayableActionUrl` which is not a property. */
    private const MESSAGE_KEYS = ['level', 'subject', 'greeting', 'salutation', 'introLines', 'outroLines', 'actionText', 'actionUrl'];

    /**
     * @param array<string, Union> $passed the data the call itself supplies
     *
     * @return array{0: array<string, Union>, 1: bool}
     */
    public static function forMailable(?string $receiver, array $passed, StatementsSource $source): array
    {
        $codebase = $source->getCodebase();
        $storage = $receiver === null ? null : ViewClassProperties::storage($codebase, $receiver);

        // The base class, a contract or a union says nothing about which properties the instance has.
        if (!$storage instanceof ClassLikeStorage || !isset($storage->parent_classes[self::MAILABLE])) {
            return [[], false];
        }

        $properties = ViewClassProperties::publicOf($codebase, $storage, static fn(): bool => false);

        if ($properties === null) {
            return [[], false];
        }

        $keys = [];

        foreach ($properties as $name => [$declaring, $property]) {
            if (\strtolower($declaring->name) !== self::MAILABLE) {
                $keys[$name] = self::typeOf($codebase, $storage, $declaring, $property);
            }
        }

        $keys['message'] = self::messageType(isset($keys['message']) || isset($passed['message']));

        return [$keys, true];
    }

    /**
     * @param array<string, Union> $passed         the data the call itself supplies
     * @param bool                 $passedComplete whether `$passed` is every key the call supplies
     *
     * @return array{0: array<string, Union>, 1: bool}
     */
    public static function forMailMessage(?string $receiver, array $passed, bool $passedComplete, StatementsSource $source): array
    {
        $codebase = $source->getCodebase();
        $storage = $receiver === null ? null : ViewClassProperties::storage($codebase, $receiver);

        if (!$storage instanceof ClassLikeStorage
            || self::overrides($storage, 'data', self::MAIL_MESSAGE)
            || self::overrides($storage, 'toarray', self::SIMPLE_MESSAGE)
        ) {
            return [[], false];
        }

        $properties = ViewClassProperties::publicOf(
            $codebase,
            $storage,
            static fn(string $name): bool => !\in_array($name, self::MESSAGE_KEYS, true),
        );

        if ($properties === null) {
            return [[], false];
        }

        $keys = [];

        // A key the call passes wins, and a passed set that is not fully known may hold any of them.
        if ($passedComplete) {
            foreach ([...self::MESSAGE_KEYS, 'displayableActionUrl'] as $name) {
                if (!isset($passed[$name])) {
                    $keys[$name] = isset($properties[$name])
                        ? self::typeOf($codebase, $storage, $properties[$name][0], $properties[$name][1])
                        : ($name === 'displayableActionUrl' ? Type::getString() : Type::getMixed());
                }
            }
        }

        $keys['message'] = self::messageType(!$passedComplete || isset($passed['message']));

        return [$keys, true];
    }

    /**
     * @return array{0: array<string, Union>, 1: bool}|null null when the analyzed class is not a
     *         Livewire component
     */
    public static function forLivewire(StatementsSource $source): ?array
    {
        $fqClassName = $source->getFQCLN();

        if ($fqClassName === null) {
            return null;
        }

        $codebase = $source->getCodebase();
        $storage = ViewClassProperties::storage($codebase, $fqClassName);

        // Ancestry only: Livewire is not required, so the class is never loaded.
        if (!$storage instanceof ClassLikeStorage || !isset($storage->parent_classes[self::LIVEWIRE])) {
            return null;
        }

        $properties = ViewClassProperties::publicOf($codebase, $storage, static fn(): bool => false);

        if ($properties === null) {
            return [[], false];
        }

        $keys = [];

        foreach ($properties as $name => [$declaring, $property]) {
            if ($property->is_static !== true && !\in_array(\strtolower($declaring->name), self::LIVEWIRE_BASES, true)) {
                $keys[$name] = self::typeOf($codebase, $storage, $declaring, $property);
            }
        }

        return [$keys, true];
    }

    /**
     * @psalm-mutation-free
     */
    private static function overrides(ClassLikeStorage $storage, string $method, string $framework): bool
    {
        $declaring = $storage->declaring_method_ids[$method] ?? null;

        // Absent for a method a stub of the class leaves out, which is the framework's own.
        return $declaring instanceof MethodIdentifier && \strtolower($declaring->fq_class_name) !== $framework;
    }

    /**
     * @psalm-pure
     */
    private static function messageType(bool $clashes): Union
    {
        return $clashes ? Type::getMixed() : new Union([new TNamedObject(Message::class)]);
    }

    private static function typeOf(
        Codebase $codebase,
        ClassLikeStorage $storage,
        ClassLikeStorage $declaring,
        PropertyStorage $property,
    ): Union {
        if (!$property->type instanceof \Psalm\Type\Union || $declaring->template_types !== null) {
            return Type::getMixed();
        }

        return TypeExpander::expandUnion(
            $codebase,
            $property->type,
            $storage->name,
            $storage->name,
            $storage->parent_class,
            final: true,
        );
    }
}
