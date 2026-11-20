--FILE--
<?php declare(strict_types=1);

use Illuminate\Cookie\CookieJar;
use Illuminate\Support\Collection;

interface InvokableFactory
{
    /** @psalm-impure */
    public function __invoke(): int;
}

// A value typed as an interface declaring `__invoke()` passes the `callable` guard. Psalm rules `\Closure` out
// (Closure is final and implements no interface, vimeo/psalm#12497), so the result stays the interface and the
// receiver keeps its value type (an issue-free pass into `accepts_factory_collection`).
/** @param Collection<string, InvokableFactory> $items */
function get_or_put_invokable_interface(Collection $items, InvokableFactory $fallback): void
{
    $_result = $items->getOrPut('key', $fallback);
    /** @psalm-check-type-exact $_result = InvokableFactory */
}

/** @param Collection<string, InvokableFactory> $_items */
function accepts_factory_collection(Collection $_items): void {}

/** @param Collection<string, InvokableFactory> $items */
function get_or_put_invokable_interface_receiver(Collection $items, InvokableFactory $fallback): void
{
    $items->getOrPut('key', $fallback);
    accepts_factory_collection($items);
}

function cookie_jar_queued_invokable_interface(CookieJar $jar, InvokableFactory $fallback): void
{
    $_result = $jar->queued('name', $fallback);
    /** @psalm-check-type-exact $_result = InvokableFactory|Symfony\Component\HttpFoundation\Cookie */
}
?>
--EXPECTF--
