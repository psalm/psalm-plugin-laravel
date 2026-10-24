<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\Mail\Message;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
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
 * uninitialized typed property still counts as supplied (a superset only ever silences a report),
 * but is typed `mixed` when the call passes the same name, as Laravel then keeps the passed value.
 * A subclass overriding `buildViewData()` or `additionalMessageData()` makes the set unknowable.
 *
 * MailMessage: `MailMessage::data()` is `array_merge(toArray(), viewData)`, so a key the call passes
 * wins over the `toArray()` one. The `toArray()` keys are `mixed`: the vendor property docblocks are
 * coarse (`array`) and wrong for the null defaults.
 *
 * Mailer: `Mailer::send()` adds `message`. `view()` and `markdown()` order that against the data
 * differently, so a call that also supplies a `message`, or whose data is not fully known, makes the
 * key `mixed`.
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

    /** Added by `Mailer::send()` and `Mailable::additionalMessageData()`; where they merge differs per path. */
    private const MAILABLE_EXTRA = ['mailer', '__laravel_mailable'];

    /** Added by `Mailer::send()` and `MailChannel::additionalMessageData()`. */
    private const MAIL_MESSAGE_EXTRA = ['mailer', '__laravel_notification_id', '__laravel_notification', '__laravel_notification_queued'];

    /**
     * @param array<string, Union> $passed         the data the call itself supplies
     * @param bool                 $passedComplete whether `$passed` is every key the call supplies
     *
     * @return array{0: array<string, Union>, 1: bool}
     */
    public static function forMailable(?string $receiver, array $passed, bool $passedComplete, StatementsSource $source): array
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

        // A userland override can add, drop or change any key, so nothing is typed and nothing is missing.
        $open = self::overrides($storage, 'buildviewdata', self::MAILABLE)
            || self::overrides($storage, 'additionalmessagedata', self::MAILABLE);
        $keys = [];

        foreach ($properties as $name => [$declaring, $property]) {
            if (\strtolower($declaring->name) !== self::MAILABLE) {
                // An uninitialized typed property is skipped at runtime and leaves a passed value of
                // the same name in place.
                $keys[$name] = $open || isset($passed[$name]) ? Type::getMixed() : self::typeOf($codebase, $storage, $name, $declaring, $property);
            }
        }

        if ($open) {
            // The override may replace any passed value too, so none of them keeps its passed type.
            foreach (\array_keys($passed) as $name) {
                $keys[$name] = Type::getMixed();
            }
        }

        $keys['message'] = self::messageType($open || !$passedComplete || isset($keys['message']) || isset($passed['message']));

        foreach (self::MAILABLE_EXTRA as $name) {
            $keys[$name] = Type::getMixed();
        }

        return [$keys, !$open];
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

        $keys = [];

        // A key the call passes wins, and a passed set that is not fully known may hold any of them.
        // `SimpleMessage`'s docblock types are coarse (`array`) and wrong for its null defaults, so
        // the keys are `mixed`: only `displayableActionUrl`, a computed string, has a trustworthy type.
        if ($passedComplete) {
            foreach ([...self::MESSAGE_KEYS, 'displayableActionUrl'] as $name) {
                if (!isset($passed[$name])) {
                    $keys[$name] = $name === 'displayableActionUrl' ? Type::getString() : Type::getMixed();
                }
            }
        }

        $keys['message'] = self::messageType(!$passedComplete || isset($passed['message']));

        foreach (self::MAIL_MESSAGE_EXTRA as $name) {
            $keys[$name] = Type::getMixed();
        }

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
                $keys[$name] = self::typeOf($codebase, $storage, $name, $declaring, $property);
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
        string $name,
        ClassLikeStorage $declaring,
        PropertyStorage $property,
    ): Union {
        if ($declaring->template_types !== null) {
            return Type::getMixed();
        }

        return ViewClassProperties::typeOf($codebase, $storage, $name, $declaring, $property, final: true) ?? Type::getMixed();
    }
}
