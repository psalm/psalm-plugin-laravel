--FILE--
<?php declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * A config leaf whose config-file expression reads the environment is typed from that
 * expression, never from the env of the machine running Psalm. Keys come from the Testbench
 * skeleton config the type suite boots. The assertions hold with the env vars set or unset.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1683
 */

function env_without_default(): void
{
    // 'key' => env('APP_KEY'); the plugin backfills a dummy key at runtime.
    $_key = config('app.key');
    /** @psalm-check-type-exact $_key = null|string */

    // 'asset_url' => env('ASSET_URL'); null at runtime when unset.
    $_assetUrl = config('app.asset_url');
    /** @psalm-check-type-exact $_assetUrl = null|string */
}

function env_with_default(): void
{
    $_name = config('app.name');
    /** @psalm-check-type-exact $_name = string */

    $_port = config('mail.mailers.smtp.port');
    /** @psalm-check-type-exact $_port = int|string */

    $_pathStyle = config('filesystems.disks.s3.use_path_style_endpoint');
    /** @psalm-check-type-exact $_pathStyle = bool|string */

    // env('CACHE_PREFIX', Str::slug(...) . '-cache-')
    $_prefix = config('cache.prefix');
    /** @psalm-check-type-exact $_prefix = string */

    // env('LOG_SYSLOG_FACILITY', LOG_USER)
    $_facility = config('logging.channels.syslog.facility');
    /** @psalm-check-type-exact $_facility = int|string */

    // env('DB_DATABASE', database_path('database.sqlite'))
    $_sqlite = config('database.connections.sqlite.database');
    /** @psalm-check-type-exact $_sqlite = string */
}

function env_wrapped_in_typed_expression(): void
{
    $_debug = config('app.debug');
    /** @psalm-check-type-exact $_debug = bool */

    $_retryAfter = config('queue.connections.database.retry_after');
    /** @psalm-check-type-exact $_retryAfter = int */

    $_stack = config('logging.channels.stack.channels');
    /** @psalm-check-type-exact $_stack = list<string> */

    $_storageUrl = config('filesystems.disks.public.url');
    /** @psalm-check-type-exact $_storageUrl = string */
}

function leaves_without_env_keep_runtime_reflection(): void
{
    $_timezone = config('app.timezone');
    /** @psalm-check-type-exact $_timezone = string */

    // 'timeout' => null is a literal null in the config file.
    $_timeout = config('mail.mailers.smtp.timeout');
    /** @psalm-check-type-exact $_timeout = null */
}

function array_shape_with_env_leaf(): void
{
    $_postmark = config('services.postmark');
    /** @psalm-check-type-exact $_postmark = array{token: null|string} */
}

function present_key_ignores_default(): void
{
    $_key = config('app.key', 'fallback');
    /** @psalm-check-type-exact $_key = null|string */
}

function facade_and_repository_forms(Repository $repository): void
{
    $_facade = Config::get('services.postmark.token');
    /** @psalm-check-type-exact $_facade = null|string */

    $_repository = $repository->get('services.postmark.token');
    /** @psalm-check-type-exact $_repository = null|string */

    $_collection = $repository->collection('services.postmark');
    /** @psalm-check-type-exact $_collection = Collection<'token', null|string> */
}

final class EnvProvenanceFigmaApi
{
    public function __construct(private readonly string $token) {}

    public static function fromConfig(): self
    {
        $token = (string) config('services.postmark.token');
        if ($token === '') {
            throw new \RuntimeException('POSTMARK_TOKEN is not set.');
        }

        return new self(token: $token);
    }

    public function token(): string
    {
        return $this->token;
    }
}

function is_string_guard(): string
{
    $key = config('services.ses.key');
    $secret = config('services.ses.secret');
    abort_unless(is_string($key) && $key !== '' && is_string($secret) && $secret !== '', 503);

    return $key . $secret;
}

function filled_guard(): bool
{
    return filled(config('services.resend.key'))
        && filled(config('services.slack.notifications.bot_user_oauth_token'));
}

function falsy_fallback(): mixed
{
    // Psalm's usual warning for `?:` on a string that may be '' or '0', the same for any env.
    return config('services.slack.notifications.channel') ?: parse_url(config()->string('app.url'), PHP_URL_HOST);
}
?>
--EXPECTF--
RiskyTruthyFalsyComparison on line %d: Operand of type null|string contains type string, which can be falsy and truthy. This can cause possibly unexpected behavior. Use strict comparison instead.
