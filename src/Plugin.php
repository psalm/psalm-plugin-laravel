<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin;

use Illuminate\Foundation\Application;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Providers\AliasStubProvider;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\CarbonStubProvider;
use Psalm\LaravelPlugin\Providers\FacadeMapProvider;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\LaravelPlugin\Util\InternalErrorReporter;
use Psalm\LaravelPlugin\Util\StubFileFinder;
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
        $output = $this->getProgress($registration);

        try {
            ApplicationProvider::bootApp();

            if ($pluginConfig->shouldUseMigrations()) {
                $this->buildSchema($pluginConfig);
            }

            // Build facade → service class map before registering handlers.
            // Handlers use FacadeMapProvider::getFacadeClasses() in getClassLikeNames()
            // to also register for facade/alias classes that proxy to their service.
            FacadeMapProvider::init($output);

            // Always called — provides type narrowing (string vs array) regardless
            // of whether findMissingTranslations is enabled
            $this->initTranslationKeyHandler($output, $pluginConfig->findMissingTranslations);

            if ($pluginConfig->findMissingViews) {
                $this->initMissingViewHandler($output);
            }

            $this->initNoEnvOutsideConfigHandler($pluginConfig, $output);

            $this->registerHandlers($registration, $pluginConfig);
            $this->registerStubs($registration, $pluginConfig, $output);
        } catch (\Throwable $throwable) {
            InternalErrorReporter::report($throwable, $output, $pluginConfig);
        }
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
        );

        foreach ($stubs as $stubFilePath) {
            $registration->addStubFile($stubFilePath);
        }

        AliasStubProvider::register($registration, self::getAliasStubLocation($pluginConfig));

        CarbonStubProvider::register($registration, $output);
    }

    private function registerHandlers(RegistrationInterface $registration, PluginConfig $pluginConfig): void
    {
        require_once __DIR__ . '/Handlers/Application/ContainerHandler.php';
        $registration->registerHooksFromClass(Handlers\Application\ContainerHandler::class);
        require_once __DIR__ . '/Handlers/Application/OffsetHandler.php';
        $registration->registerHooksFromClass(Handlers\Application\OffsetHandler::class);

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

        require_once __DIR__ . '/Handlers/Filesystem/StorageHandler.php';
        $registration->registerHooksFromClass(Handlers\Filesystem\StorageHandler::class);

        // Model property handlers are registered dynamically by ModelRegistrationHandler
        // after Psalm populates its codebase (AfterCodebasePopulated event).
        require_once __DIR__ . '/Handlers/Eloquent/ModelRegistrationHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/CustomCollectionHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelRelationshipPropertyHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelFactoryTypeProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelFactoryMethodTypeProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/FactoryCountTypeProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyAccessorHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelAttributeSubsetHandler.php';
        // ModelPropertyHandler is loaded unconditionally because BuilderAggregateHandler
        // calls ModelPropertyHandler::resolveColumnType() to narrow aggregate returns
        // even when migrations are disabled (the @property branch still applies).
        // Schema population (ModelRegistrationHandler::enableMigrations) stays gated.
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyHandler.php';
        if ($pluginConfig->shouldUseMigrations()) {
            Handlers\Eloquent\ModelRegistrationHandler::enableMigrations();
        }

        $registration->registerHooksFromClass(Handlers\Eloquent\ModelRegistrationHandler::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\ModelFactoryMethodTypeProvider::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\FactoryCountTypeProvider::class);

        // Magic method forwarding: Relation -> Builder (decorated forwarding).
        // Must be registered BEFORE BuilderScopeHandler, BuilderPluckHandler, and
        // CustomCollectionHandler — the handler returns null for non-Relation callers
        // (fast O(1) check), so downstream handlers fire unaffected.
        require_once __DIR__ . '/Util/DynamicWhereResolver.php';
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
            Util\DynamicWhereResolver::enable();
        }

        // Eloquent's Model -> Builder @mixin host correction is intentionally separate
        // from the rule-driven forwarding handler. If another forwarding domain needs
        // the same hook, this could become an optional ForwardingRule callback instead.
        Handlers\Eloquent\ModelBuilderMixinHandler::init();
        $registration->registerHooksFromClass(Handlers\Eloquent\ModelBuilderMixinHandler::class);
        $registration->registerHooksFromClass(Handlers\Magic\MethodForwardingHandler::class);

        $registration->registerHooksFromClass(Handlers\Eloquent\ModelMethodHandler::class);
        require_once __DIR__ . '/Util/ModelPropertyResolver.php';
        require_once __DIR__ . '/Handlers/Eloquent/BuilderScopeHandler.php';
        Handlers\Eloquent\BuilderScopeHandler::init();
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderScopeHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/BuilderPluckHandler.php';
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderPluckHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/BuilderAggregateHandler.php';
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderAggregateHandler::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\CustomCollectionHandler::class);

        require_once __DIR__ . '/Handlers/Collections/CollectionFilterHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionFilterHandler::class);
        require_once __DIR__ . '/Handlers/Collections/CollectionFlattenHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionFlattenHandler::class);
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

        // config() helper + Repository::get() narrowing — reflect runtime config
        // values from the booted Laravel app. See
        // https://github.com/psalm/psalm-plugin-laravel/issues/752.
        // Opt-out via `<resolveConfigReturnTypes value="false" />` for apps that
        // construct ad-hoc Repository instances (false positives possible — the
        // handler can't tell a fresh `new Repository([])` apart from the booted
        // singleton at analysis time).
        if ($pluginConfig->resolveConfigReturnTypes) {
            require_once __DIR__ . '/Util/ConfigValueReflector.php';
            require_once __DIR__ . '/Util/ThrowingConfigRepository.php';
            require_once __DIR__ . '/Util/ConfigKeyResolver.php';
            require_once __DIR__ . '/Handlers/Helpers/ConfigHelperHandler.php';
            $registration->registerHooksFromClass(Handlers\Helpers\ConfigHelperHandler::class);
            require_once __DIR__ . '/Handlers/Config/ConfigRepositoryMethodHandler.php';
            $registration->registerHooksFromClass(Handlers\Config\ConfigRepositoryMethodHandler::class);
        }

        require_once __DIR__ . '/Handlers/Translations/TranslationKeyHandler.php';
        $registration->registerHooksFromClass(Handlers\Translations\TranslationKeyHandler::class);

        require_once __DIR__ . '/Handlers/SuppressHandler.php';
        $registration->registerHooksFromClass(Handlers\SuppressHandler::class);

        require_once __DIR__ . '/Handlers/StatsHandler.php';
        $registration->registerHooksFromClass(Handlers\StatsHandler::class);

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

        require_once __DIR__ . '/Handlers/Rules/ModelMakeHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\ModelMakeHandler::class);

        // Detects timing-unsafe comparisons of secret-tainted values (CWE-208).
        // The hook is a no-op outside `--taint-analysis` runs (early-exits when
        // taint_flow_graph is null), so per-expression overhead in normal analysis
        // is negligible.
        require_once __DIR__ . '/Handlers/Rules/TimingUnsafeComparisonHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\TimingUnsafeComparisonHandler::class);

        require_once __DIR__ . '/Handlers/Rules/UndefinedBuilderMethodHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\UndefinedBuilderMethodHandler::class);

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
        // (require_once for the handler ran in initNoEnvOutsideConfigHandler() before this method.)
        $registration->registerHooksFromClass(Handlers\Rules\NoEnvOutsideConfigHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/EnvHandler.php';
        $registration->registerHooksFromClass(Handlers\Helpers\EnvHandler::class);

        // Unlike TranslationKeyHandler (which always runs for type narrowing),
        // MissingViewHandler provides no type information — skip entirely when disabled
        if ($pluginConfig->findMissingViews) {
            require_once __DIR__ . '/Handlers/Views/MissingViewHandler.php';
            $registration->registerHooksFromClass(Handlers\Views\MissingViewHandler::class);
        }

        // Flag `public` Eloquent scopes / legacy accessors (Laravel's convention is `protected` — they
        // are dispatched indirectly, never called by name). Enabled by default; silence per project via
        // the issueHandlers config (PublicModelScope / PublicModelAccessor).
        require_once __DIR__ . '/Handlers/Rules/PublicScopeAccessorVisibilityHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\PublicScopeAccessorVisibilityHandler::class);
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

        require_once __DIR__ . '/Handlers/Rules/NoEnvOutsideConfigHandler.php';
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
    private function initMissingViewHandler(\Psalm\Progress\Progress $output): void
    {
        $app = ApplicationProvider::getApp();

        // Prefer the dedicated view.finder binding; fall back to the Factory's finder
        // (ApplicationProvider may bind 'view' without registering 'view.finder')
        if ($app->bound('view.finder')) {
            /** @var \Illuminate\View\FileViewFinder $finder */
            $finder = $app->make('view.finder');
        } elseif ($app->bound('view')) {
            $factory = $app->make('view');

            if (!$factory instanceof \Illuminate\View\Factory) {
                $output->warning(
                    'Laravel plugin: findMissingViews is enabled but the view factory is not a standard instance. '
                    . 'The MissingView check will be skipped.',
                );

                return;
            }

            $finder = $factory->getFinder();
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
