--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Translation\Translator;

/**
 * Guard: a bare Contracts\Translation\Translator-typed value (parameter, not
 * the trans() producer) is never narrowed — it exposes only the contract,
 * which declares no has(). get() is on the contract and stays fine.
 */
function on_contract_receiver(Translator $translator): void
{
    $translator->has('k');

    $_value = $translator->get('k');
    /** @psalm-check-type-exact $_value = mixed */
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Translation\Translator::has does not exist
