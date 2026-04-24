--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\SupportsBasicAuth;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilderContract;
use Illuminate\Contracts\Database\Eloquent\SupportsPartialRelations;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Cloud as CloudFilesystemContract;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Contracts\Pipeline\Pipeline as PipelineContract;
use Illuminate\Contracts\Redis\Connection as RedisConnectionContract;
use Illuminate\Contracts\Routing\ResponseFactory as FactoryContract;
use Illuminate\Contracts\Support\CanBeEscapedWhenCastToString;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Contracts\Support\ValidatedData;
use Illuminate\Contracts\Validation\ValidatesWhenResolved;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse as BaseRedirectResponse;

/**
 * Stubs must declare the same `implements` clauses as the real Laravel classes.
 * Without these, Psalm rejects concrete instances where the interface is expected.
 */
final class StubInterfaceTest
{
    public function encrypterImplementsContract(\Illuminate\Encryption\Encrypter $e): EncrypterContract
    {
        return $e;
    }

    public function encrypterImplementsStringEncrypter(\Illuminate\Encryption\Encrypter $e): StringEncrypter
    {
        return $e;
    }

    public function connectionImplementsInterface(\Illuminate\Database\Connection $c): ConnectionInterface
    {
        return $c;
    }

    public function queryBuilderImplementsContract(\Illuminate\Database\Query\Builder $b): BuilderContract
    {
        return $b;
    }

    public function sessionGuardImplementsStatefulGuard(\Illuminate\Auth\SessionGuard $g): StatefulGuard
    {
        return $g;
    }

    public function sessionGuardImplementsSupportsBasicAuth(\Illuminate\Auth\SessionGuard $g): SupportsBasicAuth
    {
        return $g;
    }

    public function tokenGuardImplementsGuard(\Illuminate\Auth\TokenGuard $g): Guard
    {
        return $g;
    }

    public function mailableImplementsContract(\Illuminate\Mail\Mailable $m): MailableContract
    {
        return $m;
    }

    public function consoleKernelImplementsContract(\Illuminate\Foundation\Console\Kernel $k): KernelContract
    {
        return $k;
    }

    public function filesystemAdapterImplementsCloud(\Illuminate\Filesystem\FilesystemAdapter $a): CloudFilesystemContract
    {
        return $a;
    }

    public function responseFactoryImplementsContract(\Illuminate\Routing\ResponseFactory $f): FactoryContract
    {
        return $f;
    }

    public function validatedInputImplementsValidatedData(\Illuminate\Support\ValidatedInput $v): ValidatedData
    {
        return $v;
    }

    public function eventFakeImplementsDispatcher(\Illuminate\Support\Testing\Fakes\EventFake $f): Dispatcher
    {
        return $f;
    }

    public function htmlStringImplementsHtmlable(\Illuminate\Support\HtmlString $h): Htmlable
    {
        return $h;
    }

    public function htmlStringImplementsStringable(\Illuminate\Support\HtmlString $h): \Stringable
    {
        return $h;
    }

    public function jsImplementsHtmlable(\Illuminate\Support\Js $j): Htmlable
    {
        return $j;
    }

    public function jsImplementsStringable(\Illuminate\Support\Js $j): \Stringable
    {
        return $j;
    }

    public function stringableImplementsJsonSerializable(\Illuminate\Support\Stringable $s): \JsonSerializable
    {
        return $s;
    }

    /** @param \Illuminate\Support\Stringable $s */
    public function stringableImplementsArrayAccess($s): \ArrayAccess
    {
        return $s;
    }

    public function stringableImplementsBaseStringable(\Illuminate\Support\Stringable $s): \Stringable
    {
        return $s;
    }

    public function cacheRepositoryImplementsContract(\Illuminate\Cache\Repository $r): CacheContract
    {
        return $r;
    }

    /** @param \Illuminate\Cache\Repository $r */
    public function cacheRepositoryImplementsArrayAccess($r): \ArrayAccess
    {
        return $r;
    }

    public function containerImplementsContract(\Illuminate\Container\Container $c): ContainerContract
    {
        return $c;
    }

    /** @param \Illuminate\Container\Container $c */
    public function containerImplementsArrayAccess($c): \ArrayAccess
    {
        return $c;
    }

    public function pipelineImplementsContract(\Illuminate\Pipeline\Pipeline $p): PipelineContract
    {
        return $p;
    }

    public function redirectResponseExtendsSymfony(\Illuminate\Http\RedirectResponse $r): BaseRedirectResponse
    {
        return $r;
    }

    public function configRepositoryImplementsContract(\Illuminate\Config\Repository $r): ConfigContract
    {
        return $r;
    }

    /** @param \Illuminate\Config\Repository $r */
    public function configRepositoryImplementsArrayAccess($r): \ArrayAccess
    {
        return $r;
    }

    public function hashManagerImplementsHasher(\Illuminate\Hashing\HashManager $h): Hasher
    {
        return $h;
    }

    public function mailMessageImplementsRenderable(\Illuminate\Notifications\Messages\MailMessage $m): Renderable
    {
        return $m;
    }

    public function phpRedisConnectionImplementsContract(\Illuminate\Redis\Connections\PhpRedisConnection $c): RedisConnectionContract
    {
        return $c;
    }

    public function formRequestImplementsValidatesWhenResolved(\Illuminate\Foundation\Http\FormRequest $f): ValidatesWhenResolved
    {
        return $f;
    }

    public function belongsToImplementsEloquentBuilderContract(\Illuminate\Database\Eloquent\Relations\BelongsTo $r): EloquentBuilderContract
    {
        return $r;
    }

    public function morphOneImplementsSupportsPartialRelations(\Illuminate\Database\Eloquent\Relations\MorphOne $m): SupportsPartialRelations
    {
        return $m;
    }

    public function hasOneImplementsSupportsPartialRelations(\Illuminate\Database\Eloquent\Relations\HasOne $h): SupportsPartialRelations
    {
        return $h;
    }

    public function hasOneThroughImplementsSupportsPartialRelations(\Illuminate\Database\Eloquent\Relations\HasOneThrough $h): SupportsPartialRelations
    {
        return $h;
    }

    public function viewImplementsContract(\Illuminate\View\View $v): ViewContract
    {
        return $v;
    }

    public function viewImplementsHtmlable(\Illuminate\View\View $v): Htmlable
    {
        return $v;
    }

    /** @param \Illuminate\View\View $v */
    public function viewImplementsArrayAccess($v): \ArrayAccess
    {
        return $v;
    }

    public function lengthAwarePaginatorImplementsCanBeEscaped(\Illuminate\Pagination\LengthAwarePaginator $p): CanBeEscapedWhenCastToString
    {
        return $p;
    }
}
?>
--EXPECT--
