--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\SupportsBasicAuth;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Cloud as CloudFilesystemContract;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Contracts\Routing\ResponseFactory as FactoryContract;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\ValidatedData;
use Illuminate\Database\ConnectionInterface;

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
}
?>
--EXPECT--
