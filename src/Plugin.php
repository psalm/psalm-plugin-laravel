<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin;

use Illuminate\Foundation\Application;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\LaravelPlugin\Internal\ExperimentalIssuePolicy;
use Psalm\LaravelPlugin\Internal\InternalErrorReporter;
use Psalm\LaravelPlugin\Stubs\AliasStubProvider;
use Psalm\LaravelPlugin\Stubs\CarbonStubProvider;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\LaravelPlugin\Stubs\StubFileFinder;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/**
 * @psalm-api
 * @internal
 */
final class Plugin implements PluginEntryPointInterface
{
    /** @inheritDoc */
    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        $pluginConfig = PluginConfig::fromXml($config);
        require_once __DIR__ . '/Internal/ExperimentalIssuePolicy.php';
        require_once __DIR__ . '/Issues/UnknownModelAttribute.php';
        require_once __DIR__ . '/Issues/UndefinedModelRelation.php';
        ExperimentalIssuePolicy::apply($pluginConfig->experimental);
        $output = $this->getProgress($registration);
        $this->loadInitializationHandlers();
        $this->resetInvocationState();

        try {
            ApplicationProvider::bootApp();

            // bootstrap() failures are swallowed inside ApplicationProvider (crash
            // resistance: one bad config file must not disable the plugin for the
            // whole run). Surface them here so a degraded boot is visible in a normal
            // psalm run rather than only via `psalm-laravel diagnose` (#1096).
            $bootstrapError = ApplicationProvider::getBootstrapError();
            if ($bootstrapError instanceof \Throwable) {
                InternalErrorReporter::reportDegradedBoot($bootstrapError, $output, $pluginConfig);
            }

            if ($pluginConfig->shouldUseMigrations()) {
                $this->buildSchema($pluginConfig);
            }

            // Build facade → service class map before registering handlers.
            // Handlers use FacadeMapProvider::getFacadeClasses() in getClassLikeNames()
            // to also register for facade/alias classes that proxy to their service.
            FacadeMapProvider::init($output);

            // Reset + arm the model-metadata registry. reset() clears any stale cache entries and
            // builder statics left from a previous bootstrap in the same process (mirrors the
            // ModelMethodHandler::init / MethodForwardingHandler::init reset convention); init()
            // captures the Progress handle for deferred warm-up warnings. The actual per-model
            // warm-up runs later, in ModelRegistrationHandler's AfterCodebasePopulated pass.
            ModelMetadataRegistryBuilder::reset();
            ModelMetadataRegistry::init($output);

            // Always called — provides type narrowing (string vs array) regardless
            // of whether findMissingTranslations is enabled
            $this->initTranslationKeyHandler($output, $pluginConfig->findMissingTranslations);

            // Resolve the 'view' binding once and share it: the diagnostic init
            // (finder fallback) and the always-on view() narrowing both need it.
            $viewFactory = $this->resolveViewFactory(ApplicationProvider::getApp(), $output);

            if ($pluginConfig->findMissingViews) {
                $this->initMissingViewHandler($output, $viewFactory);
            }

            // Always called — provides type narrowing for the view() helper regardless
            // of whether findMissingViews is enabled (same split as translations above).
            $this->initViewFactoryHandler($viewFactory);

            $this->initNoEnvOutsideConfigHandler($pluginConfig, $output);

            $this->registerHandlers($registration, $pluginConfig);
            $this->registerStubs($registration, $pluginConfig, $output);
        } catch (\Throwable $throwable) {
            InternalErrorReporter::report($throwable, $output, $pluginConfig);
        }
    }

    /**
     * Centralize the explicit source-order loads for handlers initialized before
     * registration, including optional configuration branches.
     *
     * This is not an autoloader bootstrap: PluginConfig has already been loaded
     * before this method runs, and normal static calls can use Composer autoloading.
     * The registration-adjacent require_once calls remain necessary because Psalm's
     * registration API deliberately refuses to autoload handler classes.
     *
     * @psalm-suppress MissingPureAnnotation require_once changes process-wide load state.
     */
    private function loadInitializationHandlers(): void
    {
        require_once __DIR__ . '/Handlers/Rules/NoEnvOutsideConfigHandler.php';
        require_once __DIR__ . '/Handlers/Translations/TranslationKeyHandler.php';
        require_once __DIR__ . '/Handlers/Views/MissingViewHandler.php';
        require_once __DIR__ . '/Handlers/Application/ContainerResolver.php';
        require_once __DIR__ . '/Handlers/Auth/AuthConfigAnalyzer.php';
        require_once __DIR__ . '/Handlers/Auth/GuardClassResolver.php';
        require_once __DIR__ . '/Handlers/Console/CommandDefinitionAnalyzer.php';
        require_once __DIR__ . '/Handlers/Eloquent/Metadata/ModelMetadataRegistry.php';
        require_once __DIR__ . '/Handlers/Eloquent/Metadata/ModelMetadataRegistryBuilder.php';
        require_once __DIR__ . '/Handlers/Eloquent/CustomBuilderMethodHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/CustomCollectionHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelAggregatePropertyHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelFactoryMethodTypeProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyAccessorHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelRelationReturnTypeHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelRelationshipPropertyHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelRegistrationHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/RelationMethodParser.php';
        require_once __DIR__ . '/Handlers/Eloquent/Schema/SchemaStateProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/Support/RelationResolver.php';
        require_once __DIR__ . '/Handlers/Facades/AppFacadeRegistrationHandler.php';
        require_once __DIR__ . '/Handlers/Facades/DateFacadeHandler.php';
        require_once __DIR__ . '/Handlers/Facades/FacadeMethodHandler.php';
        require_once __DIR__ . '/Handlers/Helpers/NowTodayHandler.php';
        require_once __DIR__ . '/Handlers/Jobs/DispatchableHandler.php';
        require_once __DIR__ . '/Handlers/Magic/MacroRegistry.php';
        require_once __DIR__ . '/Handlers/Producers/ProducerReturnTypeHandler.php';
        require_once __DIR__ . '/Handlers/Config/ConfigKeyResolver.php';
        require_once __DIR__ . '/Handlers/Filesystem/StorageHandler.php';
        require_once __DIR__ . '/Handlers/Validation/FormRequestPropertyHandler.php';
        require_once __DIR__ . '/Handlers/Validation/ValidationRuleAnalyzer.php';
        require_once __DIR__ . '/Internal/ProxyMethodReturnTypeProvider.php';
        require_once __DIR__ . '/Stubs/FacadeMapProvider.php';
    }

    /**
     * Reset all plugin-owned state whose meaning depends on the previous Laravel
     * application, XML configuration, filesystem, aliases, or Psalm Codebase.
     *
     * This deliberately precedes boot and every optional initialization branch:
     * `init()` may be skipped, so it cannot be responsible for overwriting a
     * previous invocation's state.
     *
     * @psalm-external-mutation-free
     */
    private function resetInvocationState(): void
    {
        ApplicationProvider::reset();
        FacadeMapProvider::reset();
        Handlers\Application\ContainerResolver::reset();
        Handlers\Auth\AuthConfigAnalyzer::reset();
        Handlers\Auth\GuardClassResolver::reset();
        Handlers\Config\ConfigKeyResolver::reset();
        Handlers\Console\CommandDefinitionAnalyzer::reset();
        Handlers\Eloquent\CustomBuilderMethodHandler::reset();
        Handlers\Eloquent\CustomCollectionHandler::reset();
        Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder::reset();
        Handlers\Eloquent\ModelAggregatePropertyHandler::reset();
        Handlers\Eloquent\ModelFactoryMethodTypeProvider::reset();
        Handlers\Eloquent\ModelPropertyAccessorHandler::reset();
        Handlers\Eloquent\ModelPropertyHandler::reset();
        Handlers\Eloquent\ModelRelationReturnTypeHandler::reset();
        Handlers\Eloquent\ModelRelationshipPropertyHandler::reset();
        Handlers\Eloquent\ModelRegistrationHandler::reset();
        Handlers\Eloquent\RelationMethodParser::reset();
        Handlers\Eloquent\Support\RelationResolver::reset();
        SchemaStateProvider::reset();
        Handlers\Facades\AppFacadeRegistrationHandler::reset();
        Handlers\Facades\DateFacadeHandler::reset();
        Handlers\Facades\FacadeMethodHandler::reset();
        Handlers\Helpers\NowTodayHandler::reset();
        Handlers\Jobs\DispatchableHandler::reset();
        Handlers\Magic\MacroRegistry::reset();
        Handlers\Producers\ProducerReturnTypeHandler::reset();
        Handlers\Rules\NoEnvOutsideConfigHandler::reset();
        Handlers\Translations\TranslationKeyHandler::reset();
        Handlers\Filesystem\StorageHandler::reset();
        Handlers\Validation\FormRequestPropertyHandler::reset();
        Handlers\Validation\ValidationRuleAnalyzer::reset();
        Handlers\Views\MissingViewHandler::reset();
        Internal\ProxyMethodReturnTypeProvider::reset();
    }

    private function registerStubs(
        RegistrationInterface $registration,
        PluginConfig $pluginConfig,
        \Psalm\Progress\Progress $output,
    ): void {
        $stubsRoot = \dirname(__DIR__) . '/stubs';

        $stubs = \array_merge(
            StubFileFinder::commonStubs($stubsRoot, $output),
            StubFileFinder::stubsForLaravelVersion($stubsRoot, Application::VERSION, $output),
            $this->optionalIntegrationStubs($stubsRoot, $output),
        );

        foreach ($stubs as $stubFilePath) {
            $registration->addStubFile($stubFilePath);
        }

        AliasStubProvider::register($registration, self::getAliasStubLocation($pluginConfig));

        CarbonStubProvider::register($registration, $output);
    }

    /**
     * Stubs for optional first/third-party AI packages. Each entry guards on
     * Composer's runtime metadata so absent packages contribute zero stubs and
     * we avoid triggering the project autoloader for a class lookup. The
     * version constraint additionally protects against a future major bump
     * (e.g. laravel/ai 1.0) silently loading stubs that reference removed or
     * renamed classes.
     *
     * @return list<string>
     */
    private function optionalIntegrationStubs(string $stubsRoot, \Psalm\Progress\Progress $output): array
    {
        $stubs = [];

        if ($this->isInstalledAndSatisfies('laravel/ai', '>=0.9.0 <1.0.0')) {
            \array_push($stubs, ...StubFileFinder::integrationStubs($stubsRoot, 'laravel-ai', $output));
        }

        return $stubs;
    }

    /**
     * Composer's {@see \Composer\InstalledVersions::satisfies()} throws when the
     * package is missing entirely. Pair it with the cheap presence check first
     * so callers can express "installed AND in this range" as a single boolean.
     */
    private function isInstalledAndSatisfies(string $package, string $constraint): bool
    {
        if (!\Composer\InstalledVersions::isInstalled($package)) {
            return false;
        }

        return \Composer\InstalledVersions::satisfies(
            new \Composer\Semver\VersionParser(),
            $package,
            $constraint,
        );
    }

    private function registerHandlers(RegistrationInterface $registration, PluginConfig $pluginConfig): void
    {
        require_once __DIR__ . '/Handlers/Application/ContainerHandler.php';
        $registration->registerHooksFromClass(Handlers\Application\ContainerHandler::class);
        require_once __DIR__ . '/Handlers/Application/OffsetHandler.php';
        $registration->registerHooksFromClass(Handlers\Application\OffsetHandler::class);
        require_once __DIR__ . '/Handlers/Application/ContractMethodBridgeHandler.php';
        $registration->registerHooksFromClass(Handlers\Application\ContractMethodBridgeHandler::class);

        require_once __DIR__ . '/Handlers/Auth/AuthMethodHandler.php';
        $registration->registerHooksFromClass(Handlers\Auth\AuthMethodHandler::class);
        require_once __DIR__ . '/Handlers/Auth/AuthFunctionHandler.php';
        $registration->registerHooksFromClass(Handlers\Auth\AuthFunctionHandler::class);
        require_once __DIR__ . '/Handlers/Auth/GuardHandler.php';
        $registration->registerHooksFromClass(Handlers\Auth\GuardHandler::class);
        require_once __DIR__ . '/Handlers/Auth/RequestHandler.php';
        $registration->registerHooksFromClass(Handlers\Auth\RequestHandler::class);
        // Taint source/escape for the concrete guards. Lives in a handler, not a
        // `.phpstub`, because redeclaring the guard class to host a taint method
        // shadows every other method (see GuardTaintHandler / #1113).
        require_once __DIR__ . '/Handlers/Auth/GuardTaintHandler.php';
        $registration->registerHooksFromClass(Handlers\Auth\GuardTaintHandler::class);
        // Same shadowing trap as the guards (#1113): the encrypter is reached via container
        // narrowing (`app('encrypter')`) and carries no Macroable/__call to mask the strip, so a
        // taint `.phpstub` would break `app('encrypter')->getKey()`. Keep the taint in a handler.
        require_once __DIR__ . '/Handlers/Encryption/EncrypterTaintHandler.php';
        $registration->registerHooksFromClass(Handlers\Encryption\EncrypterTaintHandler::class);

        require_once __DIR__ . '/Handlers/Filesystem/StorageHandler.php';
        $registration->registerHooksFromClass(Handlers\Filesystem\StorageHandler::class);

        // Model property handlers are registered dynamically by ModelRegistrationHandler
        // after Psalm populates its codebase (AfterCodebasePopulated event).
        require_once __DIR__ . '/Handlers/Eloquent/CastContractUserDefinedHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelRegistrationHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/CustomCollectionHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelRelationshipPropertyHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelFactoryTypeProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelFactoryMethodTypeProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/FactoryCountTypeProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyAccessorHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelAttributeSubsetHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelToArrayShapeHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/BuilderSubclassQueryMixinHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/BuilderNativeStaticReturnTypeHandler.php';
        // ModelPropertyHandler is loaded unconditionally because BuilderAggregateHandler
        // calls ModelPropertyHandler::resolveColumnType() to narrow aggregate returns
        // even when migrations are disabled (the @property branch still applies).
        // Schema population (ModelRegistrationHandler::enableMigrations) stays gated.
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyHandler.php';
        if ($pluginConfig->shouldUseMigrations()) {
            Handlers\Eloquent\ModelRegistrationHandler::enableMigrations();
        }

        $registration->registerHooksFromClass(Handlers\Eloquent\CastContractUserDefinedHandler::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\ModelRegistrationHandler::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderSubclassQueryMixinHandler::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderNativeStaticReturnTypeHandler::class);
        // Strips the `sql` taint from a where-family `$column` argument when it is a keyed-MAP
        // (`where(['col' => $v])` binds each value — #734/#733 false positive), scoped to the exact
        // argument nodes recorded by its Before-expression hook. See the handler docblock.
        require_once __DIR__ . '/Handlers/Eloquent/WhereColumnTaintHandler.php';
        $registration->registerHooksFromClass(Handlers\Eloquent\WhereColumnTaintHandler::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\ModelFactoryMethodTypeProvider::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\FactoryCountTypeProvider::class);

        // Magic method forwarding: Relation -> Builder (decorated forwarding).
        // Must be registered BEFORE BuilderScopeHandler, BuilderPluckHandler, and
        // CustomCollectionHandler — the handler returns null for non-Relation callers
        // (fast O(1) check), so downstream handlers fire unaffected.
        require_once __DIR__ . '/Handlers/Eloquent/Support/DynamicWhereResolver.php';
        require_once __DIR__ . '/Handlers/Magic/ForwardingRule.php';
        require_once __DIR__ . '/Handlers/Magic/ReturnTypeResolver.php';
        require_once __DIR__ . '/Handlers/Magic/MethodForwardingHandler.php';
        require_once __DIR__ . '/Handlers/Magic/MacroHandler.php';
        $registration->registerHooksFromClass(Handlers\Magic\MacroHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/ModelMethodHandler.php';
        Handlers\Eloquent\ModelMethodHandler::init();
        require_once __DIR__ . '/Handlers/Eloquent/ModelBuilderMixinHandler.php';
        Handlers\Magic\MethodForwardingHandler::init(new Handlers\Magic\ForwardingRule(
            sourceClass: \Illuminate\Database\Eloquent\Relations\Relation::class,
            searchClasses: [
                \Illuminate\Database\Eloquent\Builder::class,
                \Illuminate\Database\Query\Builder::class,
            ],
            selfReturnIndicators: [\Illuminate\Database\Eloquent\Builder::class],
            // Relation subclasses (concrete + abstract bases, since Psalm hook lookup
            // is exact-class). MorphPivot is in the Relations namespace but extends
            // Model (not Relation) — intentionally excluded.
            additionalSourceClasses: [
                \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
                \Illuminate\Database\Eloquent\Relations\BelongsToMany::class,
                \Illuminate\Database\Eloquent\Relations\HasMany::class,
                \Illuminate\Database\Eloquent\Relations\HasManyThrough::class,
                \Illuminate\Database\Eloquent\Relations\HasOne::class,
                \Illuminate\Database\Eloquent\Relations\HasOneOrMany::class,
                \Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough::class,
                \Illuminate\Database\Eloquent\Relations\HasOneThrough::class,
                \Illuminate\Database\Eloquent\Relations\MorphMany::class,
                \Illuminate\Database\Eloquent\Relations\MorphOne::class,
                \Illuminate\Database\Eloquent\Relations\MorphOneOrMany::class,
                \Illuminate\Database\Eloquent\Relations\MorphTo::class,
                \Illuminate\Database\Eloquent\Relations\MorphToMany::class,
            ],
            interceptMixin: true,
        ));
        if ($pluginConfig->resolveDynamicWhereClauses) {
            Handlers\Eloquent\Support\DynamicWhereResolver::enable();
        }

        // Eloquent's Model -> Builder @mixin host correction is intentionally separate
        // from the rule-driven forwarding handler. If another forwarding domain needs
        // the same hook, this could become an optional ForwardingRule callback instead.
        Handlers\Eloquent\ModelBuilderMixinHandler::init();
        $registration->registerHooksFromClass(Handlers\Eloquent\ModelBuilderMixinHandler::class);
        $registration->registerHooksFromClass(Handlers\Magic\MethodForwardingHandler::class);

        $registration->registerHooksFromClass(Handlers\Eloquent\ModelMethodHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/Support/ModelPropertyResolver.php';
        require_once __DIR__ . '/Handlers/Eloquent/BuilderScopeHandler.php';
        Handlers\Eloquent\BuilderScopeHandler::init();
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderScopeHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/BuilderPluckHandler.php';
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderPluckHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/BuilderAggregateHandler.php';
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderAggregateHandler::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\CustomCollectionHandler::class);

        require_once __DIR__ . '/Handlers/Collections/CollectHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectHandler::class);
        require_once __DIR__ . '/Handlers/Collections/CollectionFilterHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionFilterHandler::class);
        require_once __DIR__ . '/Handlers/Collections/CollectionFlattenHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionFlattenHandler::class);
        // CollectionInputTypeResolver is a plain collaborator (no hooks) shared by
        // CollectHandler and CollectionMakeHandler, so it is require_once'd but not registered.
        require_once __DIR__ . '/Handlers/Collections/CollectionInputTypeResolver.php';
        require_once __DIR__ . '/Handlers/Collections/CollectionMakeHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionMakeHandler::class);
        require_once __DIR__ . '/Handlers/Collections/CollectionPluckHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionPluckHandler::class);
        require_once __DIR__ . '/Handlers/Collections/CollectionValuesAllHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionValuesAllHandler::class);
        require_once __DIR__ . '/Handlers/Collections/HigherOrderCollectionProxyHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\HigherOrderCollectionProxyHandler::class);

        require_once __DIR__ . '/Handlers/Support/ConditionableWhenHandler.php';
        $registration->registerHooksFromClass(Handlers\Support\ConditionableWhenHandler::class);

        require_once __DIR__ . '/Handlers/Support/TappableTapHandler.php';
        $registration->registerHooksFromClass(Handlers\Support\TappableTapHandler::class);

        require_once __DIR__ . '/Handlers/Console/CommandArgumentHandler.php';
        $registration->registerHooksFromClass(Handlers\Console\CommandArgumentHandler::class);
        require_once __DIR__ . '/Handlers/Console/ConsoleClosureScopeHandler.php';
        $registration->registerHooksFromClass(Handlers\Console\ConsoleClosureScopeHandler::class);

        require_once __DIR__ . '/Handlers/Validation/ValidatedTypeHandler.php';
        $registration->registerHooksFromClass(Handlers\Validation\ValidatedTypeHandler::class);
        // FormRequest magic-property narrowing (#1016): `$this->email`, `$user->email`.
        // Registers its property providers per-subclass at AfterCodebasePopulated
        // because Psalm's property lookup is exact-class.
        require_once __DIR__ . '/Handlers/Validation/FormRequestPropertyHandler.php';
        $registration->registerHooksFromClass(Handlers\Validation\FormRequestPropertyHandler::class);
        // Collector populates its cache via AfterExpressionAnalysisEvent on
        // $request->validate([...]) and evicts it via AfterFunctionLikeAnalysisEvent.
        // ValidationTaintHandler::removeTaints consults the cache during
        // AddRemoveTaintsEvent on subsequent $request->input('key') reads.
        // Registration order between the two is not functionally significant —
        // they subscribe to different event types — but the two stay together
        // here to keep the feed/consume relationship obvious to readers.
        require_once __DIR__ . '/Handlers/Validation/InlineValidateRulesCollector.php';
        $registration->registerHooksFromClass(Handlers\Validation\InlineValidateRulesCollector::class);
        // ValidatedFieldReadResolver interprets every validated-read syntax
        // (keyed accessor, ValidatedInput accessor, magic property, tracked
        // variable) into one answer; ValidationTaintHandler applies it. The
        // resolver and its value object are plain collaborators (no hooks), so
        // they are require_once'd but not registered.
        require_once __DIR__ . '/Handlers/Validation/ValidatedFieldRead.php';
        require_once __DIR__ . '/Handlers/Validation/ValidatedFieldReadResolver.php';
        require_once __DIR__ . '/Handlers/Validation/ValidationTaintHandler.php';
        $registration->registerHooksFromClass(Handlers\Validation\ValidationTaintHandler::class);

        require_once __DIR__ . '/Handlers/Helpers/CacheHandler.php';
        $registration->registerHooksFromClass(Handlers\Helpers\CacheHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/NowTodayHandler.php';
        $registration->registerHooksFromClass(Handlers\Helpers\NowTodayHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/PathHandler.php';
        $registration->registerHooksFromClass(Handlers\Helpers\PathHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/LiteralHandler.php';
        $registration->registerHooksFromClass(Handlers\Helpers\LiteralHandler::class);

        // config() helper + Repository::get() narrowing — reflect runtime config
        // values from the booted Laravel app. See
        // https://github.com/psalm/psalm-plugin-laravel/issues/752.
        // Opt-out via `<resolveConfigReturnTypes value="false" />` for apps that
        // construct ad-hoc Repository instances (false positives possible — the
        // handler can't tell a fresh `new Repository([])` apart from the booted
        // singleton at analysis time).
        if ($pluginConfig->resolveConfigReturnTypes) {
            require_once __DIR__ . '/Handlers/Config/ConfigValueReflector.php';
            require_once __DIR__ . '/Handlers/Config/ThrowingConfigRepository.php';
            require_once __DIR__ . '/Handlers/Config/ConfigKeyResolver.php';
            require_once __DIR__ . '/Handlers/Helpers/ConfigHelperHandler.php';
            $registration->registerHooksFromClass(Handlers\Helpers\ConfigHelperHandler::class);
            require_once __DIR__ . '/Handlers/Config/ConfigRepositoryMethodHandler.php';
            $registration->registerHooksFromClass(Handlers\Config\ConfigRepositoryMethodHandler::class);
        }

        require_once __DIR__ . '/Handlers/Translations/TranslationKeyHandler.php';
        $registration->registerHooksFromClass(Handlers\Translations\TranslationKeyHandler::class);

        require_once __DIR__ . '/Handlers/Diagnostics/SuppressHandler.php';
        $registration->registerHooksFromClass(Handlers\Diagnostics\SuppressHandler::class);

        require_once __DIR__ . '/Handlers/Diagnostics/StatsHandler.php';
        $registration->registerHooksFromClass(Handlers\Diagnostics\StatsHandler::class);

        require_once __DIR__ . '/Handlers/Jobs/DispatchableHandler.php';
        $registration->registerHooksFromClass(Handlers\Jobs\DispatchableHandler::class);

        // App-owned Facade subclasses: enumerate after codebase population and register
        // per-class method providers that resolve methods via a `getFacadeRoot()` runtime
        // probe. Covers the gap where FacadeMapProvider cannot discover a facade whose
        // accessor is a class-string or a container-resolvable binding that AliasLoader
        // never sees. See https://github.com/psalm/psalm-plugin-laravel/issues/787.
        require_once __DIR__ . '/Handlers/Facades/FacadeMethodHandler.php';
        require_once __DIR__ . '/Handlers/Facades/AppFacadeRegistrationHandler.php';
        $registration->registerHooksFromClass(Handlers\Facades\AppFacadeRegistrationHandler::class);

        // `App::make()`/`makeWith()`/`get()` class-string narrowing. Its getClassLikeNames() reads
        // FacadeMapProvider (for the `\App` alias), so it relies on init() having run above.
        require_once __DIR__ . '/Handlers/Facades/AppFacadeMakeHandler.php';
        $registration->registerHooksFromClass(Handlers\Facades\AppFacadeMakeHandler::class);

        // Date facade static calls (`Date::now()`, `Date::parse()`, `Date::create*()`, ...).
        // getClassLikeNames() reads FacadeMapProvider for the `\Date` alias, so it relies on
        // init() having run above. See https://github.com/psalm/psalm-plugin-laravel/issues/1154.
        require_once __DIR__ . '/Handlers/Facades/DateFacadeHandler.php';
        $registration->registerHooksFromClass(Handlers\Facades\DateFacadeHandler::class);

        // CacheManager::store()/driver()/memo() narrowed to the concrete Repository, on
        // both the real-manager and `Cache` facade paths (#1230). getClassLikeNames()
        // reads FacadeMapProvider for the `\Cache` alias, so it relies on init() above.
        require_once __DIR__ . '/Handlers/Cache/CacheManagerReturnTypeHandler.php';
        $registration->registerHooksFromClass(Handlers\Cache\CacheManagerReturnTypeHandler::class);

        require_once __DIR__ . '/Handlers/Rules/ModelMakeHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\ModelMakeHandler::class);

        // laravel/ai integration: LLM output as taint source. Stubs cover the prompt
        // sinks declaratively; this handler covers the property-level `$response->text`
        // source because Psalm doesn't honor `@psalm-taint-source` on properties.
        // Guarded the same way as the matching stubs in optionalIntegrationStubs().
        if ($this->isInstalledAndSatisfies('laravel/ai', '>=0.9.0 <1.0.0')) {
            require_once __DIR__ . '/Handlers/Ai/LlmOutputTaintHandler.php';
            $registration->registerHooksFromClass(Handlers\Ai\LlmOutputTaintHandler::class);
        }

        // Flags unknown attribute keys passed to mass-assignment methods (create/forceCreate/fill/
        // forceFill/update) — the #699 typo case. It is always registered and self-silences on any
        // model whose column schema is unknown (migrations disabled), so it never floods.
        require_once __DIR__ . '/Handlers/Rules/UnknownModelAttributeHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\UnknownModelAttributeHandler::class);

        // Detects timing-unsafe comparisons of secret-tainted values (CWE-208).
        // The hook is a no-op outside `--taint-analysis` runs (early-exits when
        // taint_flow_graph is null), so per-expression overhead in normal analysis
        // is negligible.
        require_once __DIR__ . '/Handlers/Rules/TimingUnsafeComparisonHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\TimingUnsafeComparisonHandler::class);

        require_once __DIR__ . '/Handlers/Rules/UndefinedBuilderMethodHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\UndefinedBuilderMethodHandler::class);

        // RelationResolver depends on RelationMethodParser. Load both collaborators and the handler
        // before registration: registerHooksFromClass() requires the handler to be preloaded.
        require_once __DIR__ . '/Handlers/Eloquent/RelationMethodParser.php';
        require_once __DIR__ . '/Handlers/Eloquent/Support/RelationResolver.php';
        require_once __DIR__ . '/Handlers/Rules/UndefinedModelRelationHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\UndefinedModelRelationHandler::class);

        // Opt-in: forbid Laravel's __callStatic/__call magic forwarding on models and require
        // the explicit Model::query()->... entry point. Off by default — the forwarding is
        // idiomatic Laravel, so this only registers when the user asks for it.
        if ($pluginConfig->reportImplicitQueryBuilderCalls) {
            require_once __DIR__ . '/Handlers/Rules/ImplicitQueryBuilderCallHandler.php';
            $registration->registerHooksFromClass(Handlers\Rules\ImplicitQueryBuilderCallHandler::class);
        }

        // Tri-state gate for the OctaneIncompatibleBinding rule:
        //   findOctaneIncompatibleBinding === null  → auto-detect via class_exists()
        //   findOctaneIncompatibleBinding === true  → force enabled
        //   findOctaneIncompatibleBinding === false → force disabled (opt-out, even if laravel/octane is installed)
        // Auto-detect uses class_exists(), which triggers the project autoloader. The
        // autoloader is already active here because ApplicationProvider booted the
        // Laravel app earlier in __invoke().
        $shouldRegisterOctaneRule = $pluginConfig->findOctaneIncompatibleBinding
            ?? \class_exists('Laravel\\Octane\\Octane');

        if ($shouldRegisterOctaneRule) {
            require_once __DIR__ . '/Handlers/Rules/OctaneIncompatibleBindingHandler.php';
            $registration->registerHooksFromClass(Handlers\Rules\OctaneIncompatibleBindingHandler::class);
        }

        // NoEnvOutsideConfigHandler must be registered BEFORE EnvHandler.
        // Both handle 'env()' via FunctionReturnTypeProviderInterface; Psalm dispatches handlers
        // in registration order and stops at the first non-null return. NoEnvOutsideConfigHandler
        // always returns null (it only emits an issue), so the chain continues to EnvHandler for
        // type narrowing. Reversing the order would silently suppress the NoEnvOutsideConfig issue.
        // The initialization helper establishes the earlier static-init source order;
        // repeat the idempotent require_once because Psalm requires this class to be loaded.
        require_once __DIR__ . '/Handlers/Rules/NoEnvOutsideConfigHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\NoEnvOutsideConfigHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/EnvHandler.php';
        $registration->registerHooksFromClass(Handlers\Helpers\EnvHandler::class);

        // Always registered (like TranslationKeyHandler): the view() helper's type
        // narrowing runs regardless of findMissingViews — only the MissingView
        // diagnostic itself is opt-in, gated internally by self::$enabled
        // (set only when initMissingViewHandler() ran, i.e. findMissingViews is true).
        require_once __DIR__ . '/Handlers/Views/MissingViewHandler.php';
        $registration->registerHooksFromClass(Handlers\Views\MissingViewHandler::class);

        // Must come AFTER MissingViewHandler: MissingViewHandler's method provider on
        // Factory/View-facade make() always returns null after emitting its diagnostic,
        // but Psalm dispatches return-type providers in registration order and stops at
        // the first non-null result. Registering the narrowing provider first would let
        // it answer before MissingViewHandler runs, silently dropping the MissingView
        // issue on View::make(). Reads FacadeMapProvider, so it relies on init() (above)
        // having already run.
        require_once __DIR__ . '/Handlers/Producers/ProducerReturnTypeHandler.php';
        // Rebuild the family index from this invocation's FacadeMapProvider aliases
        // (a reused process may have booted a different app). Must precede registration
        // so the reverse index and getClassLikeNames() agree.
        Handlers\Producers\ProducerReturnTypeHandler::reset();
        $registration->registerHooksFromClass(Handlers\Producers\ProducerReturnTypeHandler::class);

        // Flag `public` Eloquent scopes / legacy accessors (Laravel's convention is `protected` — they
        // are dispatched indirectly, never called by name). Enabled by default; silence per project via
        // the issueHandlers config (PublicModelScope / PublicModelAccessor).
        require_once __DIR__ . '/Handlers/Rules/PublicScopeAccessorVisibilityHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\PublicScopeAccessorVisibilityHandler::class);

        // Flag an Eloquent `$appends` entry with no backing accessor / class cast (#694) — a runtime
        // BadMethodCallException on toArray()/toJson(). Reads ModelMetadataRegistry, so it MUST be
        // registered AFTER ModelRegistrationHandler (warm-up); AfterCodebasePopulated handlers run in
        // registration order. Enabled by default; silence via the issueHandlers config.
        require_once __DIR__ . '/Handlers/Rules/UnresolvableAppendedModelAttributeHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\UnresolvableAppendedModelAttributeHandler::class);
    }

    /**
     * Resolve config directory paths and pass them to NoEnvOutsideConfigHandler.
     *
     * When the user has not configured any `<configDirectory>` elements, fall back to
     * the booted Laravel app's `config_path()`. Relative paths and glob patterns are
     * left as-is — the handler resolves them via glob() + realpath() at this boot step.
     *
     * The handler emits a warning via $output if the resolution produces zero directories
     * for a non-empty input — that's the typo case where every env() call would be flagged.
     */
    private function initNoEnvOutsideConfigHandler(PluginConfig $pluginConfig, \Psalm\Progress\Progress $output): void
    {
        $directories = $pluginConfig->configDirectories;

        if ($directories === []) {
            $directories = [ApplicationProvider::getApp()->configPath()];
        }

        Handlers\Rules\NoEnvOutsideConfigHandler::init($directories, $output);
    }

    /**
     * Get the Translator instance from the booted Laravel app and pass it to the handler.
     *
     * Uses Laravel's Translator::has() for key resolution, which handles PHP array files,
     * JSON files, vendor/package namespaces, and fallback locales automatically.
     *
     * Always called to enable precise type narrowing (string vs array) for translation
     * keys. The $reportMissing flag controls only whether MissingTranslation issues
     * are emitted for keys that don't exist.
     */
    private function initTranslationKeyHandler(\Psalm\Progress\Progress $output, bool $reportMissing): void
    {
        $app = ApplicationProvider::getApp();

        if (!$app->bound('translator')) {
            // Only warn when the user explicitly opted into missing translation detection —
            // without it, they just lose the bonus type narrowing, which isn't worth a warning
            if ($reportMissing) {
                $output->warning(
                    'Laravel plugin: findMissingTranslations is enabled but the translator service is not bound. '
                    . 'The MissingTranslation check will be skipped.',
                );
            }

            return;
        }

        $translator = $app->make('translator');

        if (!$translator instanceof \Illuminate\Translation\Translator) {
            if ($reportMissing) {
                $output->warning(
                    'Laravel plugin: findMissingTranslations is enabled but the translator is not an instance of '
                    . 'Illuminate\Translation\Translator. The MissingTranslation check will be skipped.',
                );
            }

            return;
        }

        Handlers\Translations\TranslationKeyHandler::init($translator, $reportMissing);
    }

    /**
     * Read view paths from the booted Laravel app and pass them to the handler.
     *
     * Uses the app's FileViewFinder which reflects config('view.paths') plus
     * any paths added by service providers during bootstrap.
     */
    private function initMissingViewHandler(\Psalm\Progress\Progress $output, ?\Illuminate\View\Factory $factory): void
    {
        $app = ApplicationProvider::getApp();

        // Prefer the dedicated view.finder binding; fall back to the pre-resolved
        // Factory's finder (ApplicationProvider may bind 'view' without 'view.finder').
        if ($app->bound('view.finder')) {
            /** @var \Illuminate\View\FileViewFinder $finder */
            $finder = $app->make('view.finder');
        } elseif ($factory instanceof \Illuminate\View\Factory) {
            $finder = $factory->getFinder();
        } elseif ($app->bound('view')) {
            // 'view' is bound but resolveViewFactory() returned null: the binding
            // threw (swallowed to a --debug line rather than disabling the plugin)
            // or resolved to a non-standard implementation.
            $output->warning(
                'Laravel plugin: findMissingViews is enabled but the view factory could not be resolved to a '
                . 'standard instance (run with --debug for the underlying cause). The MissingView check will be skipped.',
            );

            return;
        } else {
            $output->warning(
                'Laravel plugin: findMissingViews is enabled but the view finder service is not bound. '
                . 'The MissingView check will be skipped.',
            );

            return;
        }

        if (!$finder instanceof \Illuminate\View\FileViewFinder) {
            $output->warning(
                'Laravel plugin: findMissingViews is enabled but the view finder is not an instance of '
                . 'Illuminate\View\FileViewFinder. The MissingView check will be skipped.',
            );

            return;
        }

        /** @var list<string> $paths */
        $paths = $finder->getPaths();

        /** @var list<string> $extensions */
        $extensions = $finder->getExtensions();

        Handlers\Views\MissingViewHandler::init($paths, $extensions);
    }

    /**
     * Resolve the booted app's view factory class and hand it to MissingViewHandler
     * so the view() helper can narrow past the stub's contract fallback.
     *
     * Passes the resolved class or null (unbound / threw / non-standard) so the
     * handler always overwrites — never leaks — a prior app's binding in a reused
     * process. Null falls back to the stub's contract type. Unlike
     * initMissingViewHandler(), no warning is emitted: this is bonus type narrowing,
     * not an opt-in diagnostic.
     *
     * @psalm-external-mutation-free
     */
    private function initViewFactoryHandler(?\Illuminate\View\Factory $factory): void
    {
        Handlers\Views\MissingViewHandler::initViewFactory($factory instanceof \Illuminate\View\Factory ? $factory::class : null);
    }

    /**
     * Single call site for `$app->make('view')`, resolved once in __invoke() and
     * shared by initMissingViewHandler() (opt-in diagnostics) and
     * initViewFactoryHandler() (always-on type narrowing).
     */
    private function resolveViewFactory(Application $app, \Psalm\Progress\Progress $output): ?\Illuminate\View\Factory
    {
        if (!$app->bound('view')) {
            return null;
        }

        try {
            $factory = $app->make('view');
        } catch (\Throwable $throwable) {
            // A throwing 'view' binding (e.g. a closure needing runtime-only state a
            // degraded boot never prepared) must degrade this one feature, not escape
            // to __invoke()'s outer catch and disable the whole plugin — the same
            // per-probe policy FacadeMapProvider::init() applies to facade roots.
            // Keep the real cause reachable for --debug runs.
            $output->debug("Laravel plugin: resolving the 'view' binding threw: {$throwable->getMessage()}\n");

            return null;
        }

        if (!$factory instanceof \Illuminate\View\Factory) {
            return null;
        }

        return $factory;
    }

    private function buildSchema(PluginConfig $pluginConfig): void
    {
        $app = ApplicationProvider::getApp();

        // Defensive guard: getApp() is typed as Illuminate\Foundation\Application, but
        // alternative bootstraps (e.g. trimmed-down Testbench builds) may return an
        // implementation that lacks databasePath(). Skip the migration build rather
        // than fatal-erroring inside MigrationSchemaBuilder.
        if (!\method_exists($app, 'databasePath')) {
            return;
        }

        $codebase = ProjectAnalyzer::getInstance()->getCodebase();
        $cache = new Handlers\Eloquent\Schema\MigrationCache(self::getCacheLocation($pluginConfig));

        $aggregator = (new Handlers\Eloquent\Schema\MigrationSchemaBuilder($app, $codebase, $cache))->build();

        SchemaStateProvider::setSchema($aggregator);
    }

    public static function getAliasStubLocation(PluginConfig $pluginConfig): string
    {
        return self::getCacheLocation($pluginConfig) . \DIRECTORY_SEPARATOR . 'aliases.phpstub';
    }

    public static function getCacheLocation(PluginConfig $pluginConfig): string
    {
        $dir = $pluginConfig->cachePath;

        if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Cache directory '{$dir}' does not exist and could not be created.");
        }

        return $dir;
    }

    /** @psalm-mutation-free */
    private function getProgress(RegistrationInterface $registration): \Psalm\Progress\Progress
    {
        $output = new \Psalm\Progress\DefaultProgress();

        // $registration->codebase is available/public from Psalm v6.7
        // see https://github.com/vimeo/psalm/pull/11297 and https://github.com/vimeo/psalm/releases/tag/6.7.0
        if ($registration instanceof \Psalm\PluginRegistrationSocket) {
            $output = $registration->codebase->progress;
        }

        return $output;
    }
}
