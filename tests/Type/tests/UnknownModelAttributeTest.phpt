--FILE--
<?php declare(strict_types=1);

namespace App\UnknownModelAttributeGate;

use App\Models\Customer;

/**
 * UnknownModelAttribute (#699) fires only when a model's column schema is known. The type-test
 * harness boots Testbench with no migrations, so every app model has an empty schema() and the gate
 * suppresses the rule; this file must analyse clean. The empty --EXPECTF-- is thus a gate-regression
 * guard (removing the skip would make these typos fire). The verdict is unit-tested in
 * UnknownModelAttributeHandlerTest::unknownKeys(); no committed test asserts a positive typo against
 * a populated schema, because no app model here carries migration columns.
 */
function gate_suppresses_typos_when_schema_is_unknown(Customer $customer): void
{
    Customer::create(['zzz_not_a_real_attribute' => 1]);
    $customer->fill(['nonexistent_email' => 'a@b.c']);
    $customer->update(['totally_made_up' => true]);
}

/**
 * Independent of schema: a non-literal attribute array carries no statically-known keys, so the
 * rule never inspects it. Guards the literal-array precondition.
 *
 * @param array<string, mixed> $data
 */
function non_literal_arrays_are_never_inspected(Customer $customer, array $data): void
{
    $customer->fill($data);
    Customer::create($data);
}
?>
--EXPECTF--
