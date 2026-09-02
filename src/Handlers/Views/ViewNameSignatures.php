<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;

/**
 * Family table + lazy reverse index for every receiver MissingViewHandler
 * validates a view name on: the view Factory (and its contract/facade/aliases),
 * ResponseFactory, Router, MailMessage, Mailable, TestResponse, and the
 * InteractsWithViews trait.
 *
 * A separate file from MissingViewHandler because the registration surface here
 * (seven families, several facade-aliased) would otherwise crowd out the actual
 * view-name-extraction and existence-check logic that handler owns.
 *
 * Keyed on a role string rather than a class-string, because MissingViewHandler
 * dispatches by (role, method name): `view()` alone means two different argument
 * positions depending on the receiver (ResponseFactory arg 0, Router arg 1), so
 * the class alone is not enough to pick an extraction rule.
 *
 * Mirrors {@see \Psalm\LaravelPlugin\Handlers\Producers\ProducerReturnTypeHandler}'s
 * family/reverse-index shape and its reset() timing (called from
 * {@see \Psalm\LaravelPlugin\Plugin::registerHandlers()} right before this family's
 * hooks are registered, not from resetInvocationState() — must run after
 * FacadeMapProvider::init() but before getClassLikeNames()/resolveRole() are
 * consulted, so registration and dispatch never see different alias sets).
 *
 * @internal
 * @psalm-external-mutation-free
 */
final class ViewNameSignatures
{
    public const ROLE_VIEW_FACTORY = 'view-factory';

    public const ROLE_RESPONSE_FACTORY = 'response-factory';

    public const ROLE_ROUTER = 'router';

    public const ROLE_MAIL_MESSAGE = 'mail-message';

    public const ROLE_MAILABLE = 'mailable';

    public const ROLE_TEST_RESPONSE = 'test-response';

    public const ROLE_INTERACTS_WITH_VIEWS = 'interacts-with-views';

    /**
     * @var array<'view-factory'|'response-factory'|'router'|'mail-message'|'mailable'|'test-response'|'interacts-with-views', array{
     *     concrete: string,
     *     facade: class-string|null,
     *     extra: list<class-string>,
     * }>
     */
    private const FAMILIES = [
        self::ROLE_VIEW_FACTORY => [
            'concrete' => \Illuminate\View\Factory::class,
            'facade' => \Illuminate\Support\Facades\View::class,
            // The contract declares only file()/make() — never first()/renderWhen()/
            // renderUnless()/renderEach() — so registering it here cannot mis-dispatch
            // those; it only picks up contract-typed `make()` calls the concrete-only
            // registration below would otherwise miss.
            'extra' => [\Illuminate\Contracts\View\Factory::class],
        ],
        self::ROLE_RESPONSE_FACTORY => [
            'concrete' => \Illuminate\Routing\ResponseFactory::class,
            'facade' => \Illuminate\Support\Facades\Response::class,
            // response() (zero-arg helper) is typed on the contract, not the concrete.
            'extra' => [\Illuminate\Contracts\Routing\ResponseFactory::class],
        ],
        self::ROLE_ROUTER => [
            'concrete' => \Illuminate\Routing\Router::class,
            'facade' => \Illuminate\Support\Facades\Route::class,
            'extra' => [],
        ],
        self::ROLE_MAIL_MESSAGE => [
            'concrete' => \Illuminate\Notifications\Messages\MailMessage::class,
            'facade' => null,
            'extra' => [],
        ],
        self::ROLE_MAILABLE => [
            'concrete' => \Illuminate\Mail\Mailable::class,
            'facade' => null,
            'extra' => [],
        ],
        self::ROLE_TEST_RESPONSE => [
            'concrete' => \Illuminate\Testing\TestResponse::class,
            'facade' => null,
            'extra' => [],
        ],
        // A trait, not a class: Psalm dispatches a method return-type provider on the
        // DECLARING class, which for a trait method is the trait itself (the using class
        // arrives as getCalledFqClasslikeName()), so one entry covers every TestCase.
        // Same registration shape as ConditionableWhenHandler/TappableTapHandler.
        self::ROLE_INTERACTS_WITH_VIEWS => [
            'concrete' => \Illuminate\Foundation\Testing\Concerns\InteractsWithViews::class,
            'facade' => null,
            'extra' => [],
        ],
    ];

    /** @var array<lowercase-string, 'view-factory'|'response-factory'|'router'|'mail-message'|'mailable'|'test-response'|'interacts-with-views'>|null */
    private static ?array $classToRole = null;

    /**
     * Drop the reverse index so it rebuilds from FacadeMapProvider on the next
     * lookup — a reused process can boot a different app with a different alias
     * registry. See the class docblock for why this is called from
     * Plugin::registerHandlers(), not resetInvocationState().
     *
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$classToRole = null;
    }

    /**
     * @return list<string>
     * @psalm-external-mutation-free
     */
    public static function getClassLikeNames(): array
    {
        $names = [];

        foreach (self::FAMILIES as $family) {
            $names = [...$names, $family['concrete'], ...$family['extra']];

            if ($family['facade'] !== null) {
                $names[] = $family['facade'];
            }

            $names = [...$names, ...FacadeMapProvider::getFacadeClasses($family['concrete'])];
        }

        return \array_values(\array_unique($names));
    }

    /**
     * @return 'view-factory'|'response-factory'|'router'|'mail-message'|'mailable'|'test-response'|'interacts-with-views'|null
     * @psalm-external-mutation-free
     */
    public static function resolveRole(string $fqClasslikeName): ?string
    {
        if (self::$classToRole === null) {
            $classToRole = [];

            foreach (self::FAMILIES as $role => $family) {
                $classes = [$family['concrete'], ...$family['extra'], ...FacadeMapProvider::getFacadeClasses($family['concrete'])];

                if ($family['facade'] !== null) {
                    $classes[] = $family['facade'];
                }

                foreach ($classes as $class) {
                    $classToRole[\strtolower($class)] = $role;
                }
            }

            self::$classToRole = $classToRole;
        }

        return self::$classToRole[\strtolower($fqClasslikeName)] ?? null;
    }
}
