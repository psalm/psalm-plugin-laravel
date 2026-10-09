--FILE--
<?php declare(strict_types=1);

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

// Queued-model restoration passes string UUID/ULID keys through to whereKey().
function restore_by_string_key(Invoice $invoice): Builder
{
    return $invoice->newQueryForRestoration('9b1deb4d');
}
?>
--EXPECTF--
