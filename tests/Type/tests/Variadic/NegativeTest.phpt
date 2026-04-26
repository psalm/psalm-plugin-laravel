--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\ControllerMiddlewareOptions;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ServiceProvider;

/**
 * @psalm-variadic must not swallow arity checks. The annotation allows extra
 * arguments beyond the declared named parameters — it must not relax the
 * requirement for those named parameters themselves.
 *
 * These calls intentionally omit required arguments and MUST still be rejected
 * by Psalm as TooFewArguments. If any assertion vanishes, @psalm-variadic was
 * misapplied on a mandatory parameter.
 */
function container_tag_too_few(Container $c): void
{
    $c->tag('Foo');
}

function cache_tags_too_few(Repository $c): void
{
    $c->tags();
}

function filesystem_delete_too_few(Filesystem $fs): void
{
    $fs->delete();
}

function pipeline_through_too_few(Pipeline $p): void
{
    $p->through();
}

function pipeline_pipe_too_few(Pipeline $p): void
{
    $p->pipe();
}

/** @param Collection<array-key, mixed> $c */
function collection_has_too_few(Collection $c): void
{
    $c->has();
}

/** @param Collection<array-key, mixed> $c */
function collection_zip_too_few(Collection $c): void
{
    $c->zip();
}

function session_has_too_few(SessionStore $s): void
{
    $s->has();
}

function session_hasAny_too_few(SessionStore $s): void
{
    $s->hasAny();
}

function session_exists_too_few(SessionStore $s): void
{
    $s->exists();
}

function middleware_only_too_few(ControllerMiddlewareOptions $m): void
{
    $m->only();
}

function middleware_except_too_few(ControllerMiddlewareOptions $m): void
{
    $m->except();
}

function resource_only_too_few(PendingResourceRegistration $r): void
{
    $r->only();
}

function resource_except_too_few(PendingResourceRegistration $r): void
{
    $r->except();
}

function request_only_too_few(Request $r): void
{
    $r->only();
}

function request_except_too_few(Request $r): void
{
    $r->except();
}

/** @param LazyCollection<array-key, mixed> $c */
function lazy_collection_has_too_few(LazyCollection $c): void
{
    $c->has();
}

/** @param LazyCollection<array-key, mixed> $c */
function lazy_collection_has_any_too_few(LazyCollection $c): void
{
    $c->hasAny();
}

/** @param LazyCollection<array-key, mixed> $c */
function lazy_collection_doesnt_contain_too_few(LazyCollection $c): void
{
    $c->doesntContain();
}

/** @param LazyCollection<array-key, mixed> $c */
function lazy_collection_zip_too_few(LazyCollection $c): void
{
    $c->zip();
}

function message_bag_has_too_few(MessageBag $bag): void
{
    $bag->has();
}

function message_bag_missing_too_few(MessageBag $bag): void
{
    $bag->missing();
}

function service_provider_commands_too_few(ServiceProvider $sp): void
{
    $sp->commands();
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for Illuminate\Container\Container::tag - expecting tags to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Container\Container::tag saw 1
TooFewArguments on line %d: Too few arguments for Illuminate\Cache\Repository::tags - expecting names to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Cache\Repository::tags saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Filesystem\Filesystem::delete - expecting paths to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Filesystem\Filesystem::delete saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Pipeline\Pipeline::through - expecting pipes to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Pipeline\Pipeline::through saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Pipeline\Pipeline::pipe - expecting pipes to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Pipeline\Pipeline::pipe saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Support\Collection::has - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\Collection::has saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Support\Collection::zip - expecting items to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\Collection::zip saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Session\Store::has - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Session\Store::has saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Session\Store::hasAny - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Session\Store::hasany saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Session\Store::exists - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Session\Store::exists saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Routing\ControllerMiddlewareOptions::only - expecting methods to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Routing\ControllerMiddlewareOptions::only saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Routing\ControllerMiddlewareOptions::except - expecting methods to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Routing\ControllerMiddlewareOptions::except saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Routing\PendingResourceRegistration::only - expecting methods to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Routing\PendingResourceRegistration::only saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Routing\PendingResourceRegistration::except - expecting methods to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Routing\PendingResourceRegistration::except saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Http\Request::only - expecting keys to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\Traits\InteractsWithData::only saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Http\Request::except - expecting keys to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\Traits\InteractsWithData::except saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Support\LazyCollection::has - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\LazyCollection::has saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Support\LazyCollection::hasAny - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\LazyCollection::hasany saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Support\LazyCollection::doesntContain - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\LazyCollection::doesntcontain saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Support\LazyCollection::zip - expecting items to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\LazyCollection::zip saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Support\MessageBag::has - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\MessageBag::has saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Support\MessageBag::missing - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\MessageBag::missing saw 0
TooFewArguments on line %d: Too few arguments for Illuminate\Support\ServiceProvider::commands - expecting commands to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\ServiceProvider::commands saw 0
