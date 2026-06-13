<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Storage\PropertyStorage;

final class SuppressHandler implements AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface
{
    /** @var array<string, list<string>> */
    private const CLASS_LEVEL_BY_PARENT_CLASS = [
        'PropertyNotSetInConstructor' => [
            'Illuminate\Console\Command',
            'Illuminate\Foundation\Http\FormRequest',
            'Illuminate\Mail\Mailable',
            'Illuminate\Notifications\Notification',
            'Illuminate\View\Component',
        ],
        'UnusedClass' => [
            'Illuminate\Console\Command',
            'Illuminate\Support\ServiceProvider',
            'Illuminate\View\Component',
        ],
    ];

    /** @var array<string, list<string>> */
    private const CLASS_LEVEL_BY_USED_TRAITS = [
        'PropertyNotSetInConstructor' => [
            'Illuminate\Queue\InteractsWithQueue',
        ],
        // HasFactory has @template TFactory, but many Laravel models omit the
        // annotation — the framework resolves the factory class via naming
        // convention at runtime. Suppress the noise. Users who want type-safe
        // factory() calls can add @use HasFactory<ConcreteFactory>.
        'MissingTemplateParam' => [
            'Illuminate\Database\Eloquent\Factories\HasFactory',
        ],
    ];

    /**
     * Suppress class-level issues by implemented interface.
     *
     * MissingTemplateParam is suppressed for Scope implementors because our stub
     * promotes @template from method-level to class-level (see issue #207).
     * Users who don't add @implements Scope<Model> shouldn't be penalised.
     *
     * @var array<string, list<string>>
     */
    private const CLASS_LEVEL_BY_INTERFACE = [
        'MissingTemplateParam' => [
            'Illuminate\Database\Eloquent\Scope',
        ],
    ];

    /**
     * Suppress class-level issues by FQCN.
     * Less flexible — use parent class or trait based checks when possible.
     *
     * @var array<string, list<string>>
     */
    private const CLASS_LEVEL_BY_FQCN = [
        'UnusedClass' => [
            'App\Console\Kernel',
            'App\Exceptions\Handler',
            'App\Http\Controllers\Controller',
            'App\Http\Kernel',
            'App\Http\Middleware\Authenticate',
            'App\Http\Middleware\TrustHosts',
        ],
    ];

    /**
     * Suppress method-level issues by FQCN.
     * Not preferable — applications may use custom namespaces.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const METHOD_LEVEL_BY_FQCN = [
        'PossiblyUnusedMethod' => [
            'App\Http\Middleware\RedirectIfAuthenticated' => ['handle'],
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const PROPERTY_LEVEL_BY_PARENT_CLASS = [
        'NonInvariantDocblockPropertyType' => [
            'Illuminate\Console\Command' => ['description'],
            'Illuminate\Database\Eloquent\Model' => [
                'fillable',
                'guarded',
                'hidden',
                'casts',
                'appends',
                'touches',
                'with',
                'withCount',
                'connection',
                'table',
                'primaryKey',
                'keyType',
                'perPage',
                'incrementing',
                'timestamps',
                'dateFormat',
                'attributes',
                'dispatchesEvents',
                'observables',
            ],
            'Illuminate\View\Component' => ['componentName'],
        ],
    ];

    /**
     * Properties that Laravel populates through a framework-driven mechanism — a testing
     * lifecycle hook (setUp(), createApplication()), a trait lifecycle hook (setUpFaker()), or
     * a parent constructor chain (ServiceProvider::__construct() assigning $this->app) — rather
     * than from the user subclass's own constructor. Listing them by the class the user
     * typically extends or composes; the entry is resolved to the actual declaring class
     * storage (trait or parent) at codebase-populated time.
     *
     * Marking a property as "initialized" on its declaring class storage is what Psalm itself
     * does for properties with a default value or promoted constructor params (see
     * ClassLikeNodeScanner / FunctionLikeNodeScanner). The PropertyNotSetInConstructor check
     * keys off `$declaring_class_storage->initialized_properties[$property_name]`, so writing
     * there cleanly skips the un-init check for every subclass without touching their
     * `$storage->suppressed_issues` — the user's own un-initialized properties still get
     * flagged. A property-level entry in PROPERTY_LEVEL_BY_PARENT_CLASS does NOT achieve this
     * because ClassAnalyzer's PropertyNotSetInConstructor report does not consult
     * `$property_storage->suppressed_issues`.
     *
     * @var array<string, list<string>>
     */
    private const FRAMEWORK_INITIALIZED_PROPERTIES_BY_FQCN = [
        // `app` and `callbackException` are declared in the
        // `Illuminate\Foundation\Testing\Concerns\InteractsWithTestCaseLifecycle` trait used by
        // TestCase. `traitsUsedByTest` is declared on TestCase itself and assigned inside
        // `createApplication()`. See psalm/psalm-plugin-laravel#912.
        'Illuminate\Foundation\Testing\TestCase' => [
            'app',
            'callbackException',
            'traitsUsedByTest',
        ],
        // `faker` is declared on the `WithFaker` trait and assigned by `setUpFaker()`, called from
        // the trait's `setUpFakerHelpers()` lifecycle hook. Any TestCase subclass that does
        // `use WithFaker;` trips the same false positive as the entries above. Resolving by trait
        // FQCN works because the trait's own ClassLikeStorage has `declaring_property_ids['faker']`
        // pointing back to itself (set during scanning), so the mutation lands on the trait
        // storage and propagates to every user class that composes it.
        'Illuminate\Foundation\Testing\WithFaker' => [
            'faker',
        ],
        // `$app` is declared on `Illuminate\Support\ServiceProvider` and assigned in its
        // `__construct($app)`. Subclasses that declare their own constructor and call
        // `parent::__construct($app)` (the documented pattern — packages routinely subclass
        // EventServiceProvider / AuthServiceProvider / RouteServiceProvider and add their own
        // constructor for runtime registration) get `PropertyNotSetInConstructor` because Psalm
        // does not trace `parent::__construct` through to parent-property assignments when
        // checking the child. Marking the property as initialized on the declaring class
        // storage skips the check for every subclass without touching their own un-initialized
        // property reports. See psalm/psalm-plugin-laravel#945.
        'Illuminate\Support\ServiceProvider' => [
            'app',
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const METHOD_LEVEL_BY_PARENT_CLASS = [
        'MissingReturnType' => [
            'Illuminate\Database\Migrations\Migration' => ['up', 'down'],
        ],
        'PossiblyUnusedMethod' => [
            // __construct included because Console commands are instantiated by the framework
            // exclusively through the container: `Console\Kernel::resolve()` and `Application::call()`
            // route through `Container::build()`, which reflects on `__construct` to inject
            // dependencies. The class name never appears as `new ConcreteCommand(...)` in user code,
            // so Psalm marks the constructor unreachable from any visible entry point. `handle`
            // is here for the same dispatch reason (called via Container::call). See psalm/psalm-plugin-laravel#943.
            'Illuminate\Console\Command' => ['__construct', 'handle'],
            'Illuminate\Database\Migrations\Migration' => ['up', 'down'],
            'Illuminate\Database\Seeder' => ['run'],
            'Illuminate\Foundation\Http\FormRequest' => [
                'after',
                'authorize',
                'rules',
                'validator',
                'validationRules',
                'withValidator',
            ],
            // __construct included because Mailable subclasses typically have their `new` call
            // sites only inside controller actions / service methods. Those enclosing methods
            // are themselves only invoked by Laravel through reflection (router, container),
            // so Psalm marks them unreachable from any visible entry point — and `new MyMail()`
            // sitting inside them inherits that unreachability, leaving `__construct` reported
            // as `PossiblyUnusedMethod`. Verified against IxDF's real codebase. The visibility
            // filter in `suppressFrameworkHookMethod()` keeps non-public constructors flagged
            // (a `protected __construct` would fail at `new` from outside the class anyway).
            //
            // `build` is dispatched via `Container::getInstance()->call([$this, 'build'])` from
            // `Mailable::prepareMailableForDelivery()` — the call_user_func_array inside Container
            // is in BoundMethod's lexical scope, which is unrelated to the user's Mailable, so
            // public is required. envelope() / content() / attachments() live in
            // suppressMailableLifecycleMethods() because they are invoked via $this->method() from
            // Mailable's own parent code: public and protected overrides work, but PHP scopes
            // private methods to the declaring subclass so a `private envelope()` cannot be
            // resolved by the parent's $this->envelope() and is left flagged by design.
            'Illuminate\Mail\Mailable' => ['__construct', 'build'],
            // toXxx() channel-render methods are handled by suppressNotificationChannelMethods()
            // because the set of channels is open-ended (core, first-party packages like
            // laravel/slack-notification-channel, community packages from
            // laravel-notification-channels.com, plus user-defined custom channels).
            //
            // Queue-only hooks (viaConnections / viaQueues) live in
            // suppressNotificationQueueHooks() — NotificationSender::queueNotification() only
            // reads them when the notification implements ShouldQueue. Listing them here would
            // hide real dead code on synchronous notifications.
            'Illuminate\Notifications\Notification' => [
                // __construct included for the same reason as Mailable: see the comment above.
                // The visibility filter keeps non-public constructors flagged.
                '__construct',
                'broadcastAs',
                'broadcastOn',
                'broadcastWith',
                'shouldSend',
                'via',
            ],
            // __construct included because service providers are instantiated by the framework
            // via `Application::resolveProvider()`, which does `new $providerClass($this)` against
            // the FQCN registered through `$this->app->register(Provider::class)` or
            // `extra.laravel.providers` in composer.json. The class name never appears as
            // `new ConcreteProvider(...)` in user code, so Psalm marks the constructor unreachable.
            // `boot` is here for the same dispatch reason (called via Container::call).
            // See psalm/psalm-plugin-laravel#943.
            'Illuminate\Support\ServiceProvider' => ['__construct', 'boot'],
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const METHOD_LEVEL_BY_USED_TRAITS = [
        'PossiblyUnusedMethod' => [
            'Illuminate\Foundation\Events\Dispatchable' => ['broadcastOn'],
            'Illuminate\Foundation\Bus\Dispatchable' => ['handle'],
            // The combined Queueable trait Laravel 11+ uses for `make:job` scaffolds
            // (composes Bus\Queueable, InteractsWithQueue, etc). The `handle()` entry
            // point is invoked by the queue worker via Container::call(), not from
            // user code — without this suppression, default queued jobs always trip
            // PossiblyUnusedMethod.
            'Illuminate\Foundation\Queue\Queueable' => ['handle'],
        ],
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $classStorage = $event->getStorage();

        if (!$classStorage->user_defined) {
            return;
        }

        if ($classStorage->is_interface) {
            return;
        }

        foreach (self::CLASS_LEVEL_BY_FQCN as $issue => $classNames) {
            if (\in_array($classStorage->name, $classNames, true)) {
                self::suppress($issue, $classStorage);
            }
        }

        foreach (self::METHOD_LEVEL_BY_FQCN as $issue => $method_by_class) {
            foreach ($method_by_class[$classStorage->name] ?? [] as $method_name) {
                /** @psalm-suppress RedundantFunctionCall method names in constants may contain uppercase */
                $method_storage = $classStorage->methods[\strtolower($method_name)] ?? null;
                if ($method_storage instanceof MethodStorage) {
                    self::suppressFrameworkHookMethod($issue, $method_storage);
                }
            }
        }
    }

    /**
     * Hierarchy-based suppressions run after codebase population, when parent_classes is fully resolved.
     * This fixes the issue where AfterClassLikeVisit only has one level of parent hierarchy.
     */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $provider = $event->getCodebase()->classlike_storage_provider;

        foreach ($provider::getAll() as $classStorage) {
            if (!$classStorage->user_defined) {
                continue;
            }

            if ($classStorage->is_interface) {
                continue;
            }

            self::suppressByParentClass($classStorage, $provider);
            self::suppressByUsedTraits($classStorage);
            self::suppressByInterface($classStorage);
        }

        self::markFrameworkInitializedProperties($provider);
    }

    /**
     * Mark framework-initialized properties as initialized on the class that declares them.
     *
     * `declaring_property_ids` is populated by Psalm's Populator after inheritance resolution,
     * so a property inherited via a trait points at the trait's storage and a property declared
     * on the parent class points at the parent. Writing `initialized_properties[$name] = true`
     * there is the same signal Psalm emits for properties with a default value, which the
     * PropertyNotSetInConstructor check honours without further configuration. The user's own
     * declared (and genuinely un-initialised) properties are unaffected.
     */
    private static function markFrameworkInitializedProperties(ClassLikeStorageProvider $provider): void
    {
        foreach (self::FRAMEWORK_INITIALIZED_PROPERTIES_BY_FQCN as $className => $propertyNames) {
            if (!$provider->has($className)) {
                continue;
            }

            $classStorage = $provider->get($className);

            foreach ($propertyNames as $propertyName) {
                $declaringClass = $classStorage->declaring_property_ids[$propertyName] ?? null;
                if ($declaringClass === null) {
                    continue;
                }

                if (!$provider->has($declaringClass)) {
                    continue;
                }

                self::markPropertyInitialized($provider->get($declaringClass), $propertyName);
            }
        }
    }

    private static function markPropertyInitialized(ClassLikeStorage $storage, string $propertyName): void
    {
        $storage->initialized_properties[$propertyName] = true;
    }

    private static function suppressByParentClass(
        ClassLikeStorage $classStorage,
        ClassLikeStorageProvider $provider,
    ): void {
        $parents = $classStorage->parent_classes;

        if ($parents === []) {
            return;
        }

        foreach (self::CLASS_LEVEL_BY_PARENT_CLASS as $issue => $parent_classes) {
            if (\array_intersect($parents, $parent_classes)) {
                self::suppress($issue, $classStorage);
            }
        }

        foreach (self::PROPERTY_LEVEL_BY_PARENT_CLASS as $issue => $properties_by_parent_class) {
            foreach ($properties_by_parent_class as $parent_class => $property_names) {
                if (!\in_array($parent_class, $parents, true)) {
                    continue;
                }

                foreach ($property_names as $property_name) {
                    $property_storage = $classStorage->properties[$property_name] ?? null;
                    if ($property_storage instanceof PropertyStorage) {
                        self::suppress($issue, $property_storage);
                    }
                }
            }
        }

        foreach (self::METHOD_LEVEL_BY_PARENT_CLASS as $issue => $methods_by_parent_class) {
            foreach ($methods_by_parent_class as $parent_class => $method_names) {
                if (!\in_array($parent_class, $parents, true)) {
                    continue;
                }

                foreach ($method_names as $method_name) {
                    $method_storage = $classStorage->methods[\strtolower($method_name)] ?? null;
                    if ($method_storage instanceof MethodStorage) {
                        self::suppressFrameworkHookMethod($issue, $method_storage);
                    }
                }
            }
        }

        if (\in_array('Illuminate\Database\Eloquent\Model', $parents, true)) {
            self::suppressEloquentAccessorMethods($classStorage, $provider);
            self::suppressEloquentScopeMethods($classStorage, $provider);
            self::suppressLegacyEloquentScopeMethods($classStorage, $provider);
        }

        if (\in_array('Illuminate\Notifications\Notification', $parents, true)) {
            self::suppressNotificationChannelMethods($classStorage);
            self::suppressNotificationQueueHooks($classStorage);
        }

        if (\in_array('Illuminate\Mail\Mailable', $parents, true)) {
            self::suppressMailableLifecycleMethods($classStorage);
        }

        if (\in_array('Illuminate\Database\Eloquent\Factories\Factory', $parents, true)) {
            self::suppressFactoryMissingTCount($classStorage);
        }
    }

    /**
     * Suppress MissingTemplateParam on Factory subclasses that bind `TModel`
     * but skip the optional `TCount`.
     *
     * The Factory stub (see stubs/common/Database/Eloquent/Factories/Factory.phpstub)
     * adds a second `@template TCount` with default `null`, used by
     * FactoryCountTypeProvider to encode plurality across count()/times()
     * chains. User-defined factories declared as
     * `class UserFactory extends Factory<User>` only specify one template arg,
     * but Psalm's MissingTemplateParam check ignores template defaults during
     * inheritance — that warning is plugin-induced noise, not a user bug.
     *
     * The check is intentionally narrow: only suppress when the user has bound
     * `TModel`. A Factory subclass without ANY `@extends` annotation is
     * actually missing TModel (a real typing issue), and Psalm should still
     * surface it.
     */
    private static function suppressFactoryMissingTCount(ClassLikeStorage $classStorage): void
    {
        $factoryParams
            = $classStorage->template_extended_params['Illuminate\Database\Eloquent\Factories\Factory'] ?? null;

        if (\is_array($factoryParams) && isset($factoryParams['TModel'])) {
            self::suppress('MissingTemplateParam', $classStorage);
        }
    }

    /**
     * Yield, for every method that *appears* on $classStorage, the MethodStorage of the class that
     * actually declares it — the model itself, a trait it composes, or a parent model.
     *
     * Eloquent hosts scopes and accessors on traits at least as often as on the model body, and
     * Psalm never copies a trait method's MethodStorage into the using class's `$storage->methods`
     * (Populator::inheritMethodsFromParent only wires up appearing_method_ids / declaring_method_ids,
     * never `methods`). It also reads a method's `suppressed_issues` — during the unused-method check
     * in ClassLikes::checkMethodReferences() — off the DECLARING class's storage, not the using
     * model's. So iterating `$classStorage->methods` both misses trait-hosted scopes entirely and,
     * even where a model-local override existed, would mutate the wrong object. Resolving to the
     * declaring storage mirrors markFrameworkInitializedProperties() (declaring_property_ids) and
     * BuilderScopeHandler::hasScopeAttribute() (#1046).
     *
     * Only methods whose appearing class is $classStorage itself are visited, matching
     * checkMethodReferences() which flags an unused method on the class where it appears. A scope
     * inherited from a parent model appears — and is checked, and gets suppressed — when that parent
     * is iterated separately in afterCodebasePopulated(), so the parent path is covered there rather
     * than double-handled here.
     *
     * Caveat: the resolved MethodStorage is shared with every other class composing the same trait,
     * so suppressing here also silences the scope on a non-Model class that happens to use the trait.
     * That is harmless — a genuine Eloquent scope trait is only ever composed by models, and Psalm
     * reads the same shared storage for all composers.
     *
     * Mutation-free: this only reads the storage graph and yields existing MethodStorage instances.
     * The suppression mutation happens in the callers (suppress*Methods), on the yielded objects.
     *
     * Scope: only the three Eloquent suppressors (accessor / #[Scope] / legacy scopeXxx) route
     * through here, because trait-hosted scopes and accessors are idiomatic. The notification /
     * mailable hook suppressors below intentionally keep their direct `$classStorage->methods`
     * lookup — trait-hosted toMail()/envelope()/viaQueues() are rare enough not to warrant the
     * declaring-storage resolution. Use this helper for any future trait-hostable suppressor.
     *
     * @return \Generator<lowercase-string, MethodStorage>
     *
     * @psalm-mutation-free
     */
    private static function appearingMethods(
        ClassLikeStorage $classStorage,
        ClassLikeStorageProvider $provider,
    ): \Generator {
        foreach ($classStorage->appearing_method_ids as $methodName => $appearingMethodId) {
            if ($appearingMethodId->fq_class_name !== $classStorage->name) {
                continue;
            }

            $declaringMethodId = $classStorage->declaring_method_ids[$methodName] ?? $appearingMethodId;

            if (!$provider->has($declaringMethodId->fq_class_name)) {
                continue;
            }

            $declaringMethodStorage
                = $provider->get($declaringMethodId->fq_class_name)->methods[$declaringMethodId->method_name] ?? null;

            if ($declaringMethodStorage instanceof MethodStorage) {
                yield $methodName => $declaringMethodStorage;
            }
        }
    }

    /**
     * Suppress PossiblyUnusedMethod for legacy Eloquent accessor/mutator methods.
     *
     * Methods matching getXxxAttribute() and setXxxAttribute() are invoked via
     * Eloquent's __get()/__set() magic when accessing $model->xxx — Psalm cannot
     * see these call sites, so it incorrectly reports them as possibly unused.
     *
     * Visibility: Eloquent dispatches accessors via `$this->{'get'.$key.'Attribute'}(...)`
     * inside `Model::mutateAttribute()` (in `HasAttributes::mutateAttribute()`). The call
     * lives on `$this` from the Model parent's scope, so public and protected overrides
     * work at runtime. PHP scopes `private` methods to the declaring subclass — the parent
     * cannot resolve a private override and would fatal at runtime — so route through
     * `suppressInternalDispatchMethod()`, which keeps `private` accessors flagged as the
     * real bug they are.
     *
     * Trait-hosted accessors (e.g. a `HasSlug` trait declaring getSlugAttribute()) hit the same
     * dispatch path, so resolve through appearingMethods() to reach the declaring storage rather
     * than iterating the model's own `methods` (which never holds trait copies).
     *
     * Note: method names are stored lowercase in ClassLikeStorage.
     */
    private static function suppressEloquentAccessorMethods(
        ClassLikeStorage $classStorage,
        ClassLikeStorageProvider $provider,
    ): void {
        foreach (self::appearingMethods($classStorage, $provider) as $methodName => $methodStorage) {
            if (\preg_match('/^get.+attribute$/', $methodName) || \preg_match('/^set.+attribute$/', $methodName)) {
                self::suppressInternalDispatchMethod('PossiblyUnusedMethod', $methodStorage);
            }
        }
    }

    /**
     * Suppress PossiblyUnusedMethod / UnusedMethod for methods annotated with #[Scope].
     *
     * Eloquent dispatches modern scopes through `Builder::callNamedScope()` / `Builder::__call()`
     * (which routes via `Model::__call()` and reflection). The call site `$builder->published()`
     * never references the model method directly, so Psalm cannot link it back to the declaration
     * and reports `PossiblyUnusedMethod` (or `UnusedMethod` under `findUnusedCode=true`). The plugin
     * already covers the type/visibility side via `BuilderScopeHandler` / `ModelMethodHandler` —
     * this fixes the suppression side. See psalm/psalm-plugin-laravel#874.
     *
     * Visibility: routed through `suppressInternalDispatchMethod()` so `public` and `protected`
     * stay silenced, but `private` stays flagged. At runtime Eloquent invokes the scope on the
     * model instance from `Builder`'s foreign scope — a `private` scope is unreachable from that
     * dispatch site and would fatal, so leaving it reported surfaces the real bug.
     */
    private static function suppressEloquentScopeMethods(
        ClassLikeStorage $classStorage,
        ClassLikeStorageProvider $provider,
    ): void {
        foreach (self::appearingMethods($classStorage, $provider) as $methodStorage) {
            if (!self::hasScopeAttribute($methodStorage)) {
                continue;
            }

            self::suppressInternalDispatchMethod('PossiblyUnusedMethod', $methodStorage);
            self::suppressInternalDispatchMethod('UnusedMethod', $methodStorage);
        }
    }

    /** @psalm-mutation-free */
    private static function hasScopeAttribute(MethodStorage $methodStorage): bool
    {
        // A private #[Scope] is not a usable scope on any supported Laravel — see the rationale
        // on BuilderScopeHandler::hasScopeAttribute. Leave it reported as genuinely unused rather
        // than silencing dead code. (suppressInternalDispatchMethod also gates private downstream;
        // this keeps the helper itself honest.)
        if ($methodStorage->visibility === ClassLikeAnalyzer::VISIBILITY_PRIVATE) {
            return false;
        }

        foreach ($methodStorage->attributes as $attribute) {
            if ($attribute->fq_class_name === Scope::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suppress PossiblyUnusedMethod / UnusedMethod for legacy `scopeXxx()` Eloquent methods.
     *
     * Same dispatch problem as `#[Scope]` (see `suppressEloquentScopeMethods()` above), only
     * older: `$builder->active()` resolves to `$model->scopeActive(...)` via
     * `Model::callNamedScope()` (`{'scope'.ucfirst($scope)}(...)`). The call site never
     * references the model method directly, so Psalm reports the scope method as unused.
     * Sibling of psalm/psalm-plugin-laravel#874, deferred there to keep the diff focused.
     *
     * Detection: lowercase method key starts with `scope` AND the original-cased name has an
     * uppercase ASCII letter directly after `scope` — the `scope` + StudlyCase convention Laravel
     * resolves via `'scope'.ucfirst($scope)` in `Model::callNamedScope()`. PHP method dispatch is
     * case-insensitive, so a `scopeactive()` method *is* technically reachable as `active()`; we
     * still require the uppercase letter because matching lowercase-after-`scope` would over-suppress
     * ordinary methods that only share the prefix (`scoped()`, `scopes()`, `scopedQuery()`). A literal
     * `scope()` is already excluded earlier by the length guard. The convention gate trades a
     * near-zero-frequency miss for far fewer false suppressions.
     *
     * Visibility: same routing as the modern variant — `private` stays flagged because the
     * dispatch lives on `$this` in `Model::callNamedScope()` and a `private` override on a
     * subclass would not be resolvable from the parent scope (PHP private scoping).
     */
    private static function suppressLegacyEloquentScopeMethods(
        ClassLikeStorage $classStorage,
        ClassLikeStorageProvider $provider,
    ): void {
        foreach (self::appearingMethods($classStorage, $provider) as $methodName => $methodStorage) {
            if (!self::isLegacyScopeMethodName($methodName, $methodStorage->cased_name)) {
                continue;
            }

            self::suppressInternalDispatchMethod('PossiblyUnusedMethod', $methodStorage);
            self::suppressInternalDispatchMethod('UnusedMethod', $methodStorage);
        }
    }

    /** @psalm-pure */
    private static function isLegacyScopeMethodName(string $lowercaseName, ?string $casedName): bool
    {
        if ($casedName === null || \strlen($casedName) < 6) {
            return false;
        }

        if (!\str_starts_with($lowercaseName, 'scope')) {
            return false;
        }

        // Laravel resolves $builder->active() via `scope` . ucfirst('active') => `scopeActive`.
        // PHP dispatch is case-insensitive so a `scopeactive()` would technically work, but we gate
        // on the `scope` + StudlyCase convention to avoid over-suppressing methods like `scoped()`.
        $firstNameChar = $casedName[5];

        return $firstNameChar >= 'A' && $firstNameChar <= 'Z';
    }

    /**
     * Suppress PossiblyUnusedMethod for Notification toXxx() channel-render methods.
     *
     * Each notification channel calls $notification->to{ChannelName}(...) via method_exists()
     * (e.g. DatabaseChannel calls toDatabase, BroadcastChannel calls toBroadcast, MailChannel
     * calls toMail). The set of channels is open-ended — core ships only a few, but first-party
     * packages (laravel/slack-notification-channel, laravel/vonage-notification-channel),
     * community packages (see laravel-notification-channels.com), and user-defined custom
     * channels each add their own. Listing every channel name explicitly is not maintainable, so
     * suppress the whole prefix instead. The trade-off is mild: a method named "toCalendar"
     * that the user genuinely never calls would be silently suppressed, but that's preferable
     * to false-positive PossiblyUnusedMethod reports on real channel render hooks.
     *
     * Note: method names are stored lowercase in ClassLikeStorage.
     */
    private static function suppressNotificationChannelMethods(ClassLikeStorage $classStorage): void
    {
        foreach ($classStorage->methods as $methodName => $methodStorage) {
            if (\preg_match('/^to.+/', $methodName)) {
                self::suppressFrameworkHookMethod('PossiblyUnusedMethod', $methodStorage);
            }
        }
    }

    /**
     * Suppress PossiblyUnusedMethod for Mailable lifecycle hooks invoked internally on `$this`.
     *
     * `envelope()` / `content()` / `attachments()` are called from `Mailable`'s own parent code
     * via `$this->envelope()` / `$this->content()` / `$this->attachments()` (see
     * `prepareMailableForDelivery()`, `ensureEnvelopeIsHydrated()`, etc.). The dispatch lives in
     * the Mailable class hierarchy, so public and protected overrides work at runtime. PHP
     * scopes `private` methods to the declaring subclass — the parent cannot resolve a private
     * override and would fatal at runtime — so route through `suppressInternalDispatchMethod()`,
     * which keeps `private` overrides flagged as the real bug they are.
     *
     * Contrast with `build`, which is dispatched via
     * `Container::getInstance()->call([$this, 'build'])` from BoundMethod's foreign scope and
     * so requires public visibility — that one stays in METHOD_LEVEL_BY_PARENT_CLASS, gated by
     * `suppressFrameworkHookMethod()`.
     */
    private static function suppressMailableLifecycleMethods(ClassLikeStorage $classStorage): void
    {
        // Names are already lowercase, matching the keying convention of $classStorage->methods.
        foreach (['envelope', 'content', 'attachments'] as $methodName) {
            $methodStorage = $classStorage->methods[$methodName] ?? null;
            if ($methodStorage instanceof MethodStorage) {
                self::suppressInternalDispatchMethod('PossiblyUnusedMethod', $methodStorage);
            }
        }
    }

    /**
     * Suppress PossiblyUnusedMethod for queue-only Notification hooks.
     *
     * `viaConnections()` and `viaQueues()` are only consulted from
     * `NotificationSender::queueNotification()`, which is reached exclusively when
     * `$notification instanceof ShouldQueue`. On synchronous notifications these methods
     * are never called — let Psalm surface them as `PossiblyUnusedMethod` so the developer
     * notices the dead code (likely indicates a forgotten `implements ShouldQueue`).
     */
    private static function suppressNotificationQueueHooks(ClassLikeStorage $classStorage): void
    {
        if (!isset($classStorage->class_implements[\strtolower('Illuminate\Contracts\Queue\ShouldQueue')])) {
            return;
        }

        foreach (['viaConnections', 'viaQueues'] as $methodName) {
            $methodStorage = $classStorage->methods[\strtolower($methodName)] ?? null;
            if ($methodStorage instanceof MethodStorage) {
                self::suppressFrameworkHookMethod('PossiblyUnusedMethod', $methodStorage);
            }
        }
    }

    private static function suppressByInterface(ClassLikeStorage $classStorage): void
    {
        if ($classStorage->class_implements === []) {
            return;
        }

        foreach (self::CLASS_LEVEL_BY_INTERFACE as $issue => $interfaces) {
            foreach ($interfaces as $interface) {
                if (isset($classStorage->class_implements[\strtolower($interface)])) {
                    self::suppress($issue, $classStorage);
                    break;
                }
            }
        }
    }

    private static function suppressByUsedTraits(ClassLikeStorage $classStorage): void
    {
        if ($classStorage->used_traits === []) {
            return;
        }

        foreach (self::CLASS_LEVEL_BY_USED_TRAITS as $issue => $traits) {
            foreach ($traits as $trait) {
                if (isset($classStorage->used_traits[\strtolower($trait)])) {
                    self::suppress($issue, $classStorage);
                    break;
                }
            }
        }

        foreach (self::METHOD_LEVEL_BY_USED_TRAITS as $issue => $methods_by_trait) {
            foreach ($methods_by_trait as $trait => $method_names) {
                if (!isset($classStorage->used_traits[\strtolower($trait)])) {
                    continue;
                }

                foreach ($method_names as $method_name) {
                    $method_storage = $classStorage->methods[\strtolower($method_name)] ?? null;
                    if ($method_storage instanceof MethodStorage) {
                        self::suppressFrameworkHookMethod($issue, $method_storage);
                    }
                }
            }
        }
    }

    private static function suppress(string $issue, ClassLikeStorage|PropertyStorage|MethodStorage $storage): void
    {
        if (!\in_array($issue, $storage->suppressed_issues, true)) {
            $storage->suppressed_issues[] = $issue;
        }
    }

    /**
     * Suppress an issue on a method only if the method is public.
     *
     * Laravel dispatches framework hooks via `method_exists()` + `Container::call()`,
     * `$instance->method()`, or reflection — all of which require the target method to be
     * public. A non-public override of a framework hook (e.g. `protected function handle()`
     * on a Console\Command, `private function up()` on a Migration) is a runtime bug, not
     * a candidate for suppression. Skip non-public methods so Psalm can still surface them.
     */
    private static function suppressFrameworkHookMethod(string $issue, MethodStorage $methodStorage): void
    {
        if ($methodStorage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
            return;
        }

        self::suppress($issue, $methodStorage);
    }

    /**
     * Suppress an issue on a method only if the method is public or protected.
     *
     * For hooks dispatched on `$this` from a parent class's own code (Eloquent accessors via
     * `Model::mutateAttribute()`, Mailable lifecycle via `Mailable::ensureXIsHydrated()`),
     * PHP scopes `private` overrides to the declaring subclass — so the parent's
     * `$this->method()` call cannot resolve a private override and triggers a fatal error.
     * `protected` works because the parent class is in the visibility chain. Skip `private`
     * here so a `private getNameAttribute()` / `private envelope()` stays reported.
     */
    private static function suppressInternalDispatchMethod(string $issue, MethodStorage $methodStorage): void
    {
        if ($methodStorage->visibility === ClassLikeAnalyzer::VISIBILITY_PRIVATE) {
            return;
        }

        self::suppress($issue, $methodStorage);
    }
}
