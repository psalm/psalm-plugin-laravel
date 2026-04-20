--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Both Filesystem::delete() and FilesystemAdapter::delete() branch on is_array($paths)
 * and fall back to func_get_args() for variadic string paths.
 */
function filesystem_delete_variadic(Filesystem $fs): void
{
    $_single = $fs->delete('file.txt');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $fs->delete('a.txt', 'b.txt', 'c.txt');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $fs->delete(['a.txt', 'b.txt']);
    /** @psalm-check-type-exact $_array = bool */
}

function filesystem_adapter_delete_variadic(FilesystemAdapter $disk): void
{
    $_single = $disk->delete('file.txt');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $disk->delete('a.txt', 'b.txt', 'c.txt');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $disk->delete(['a.txt', 'b.txt']);
    /** @psalm-check-type-exact $_array = bool */
}
?>
--EXPECTF--
