--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Ledger extends Model
    {
    }
}

namespace Database\Factories {
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * Pterodactyl pattern: no `$model`, no `@extends`. The plugin should
     * strip the `Factory` suffix and resolve `App\Models\Ledger` via the
     * Laravel default model namespace.
     */
    final class LedgerFactory extends Factory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Ledger {
    use Database\Factories\LedgerFactory;

    $_one = (new LedgerFactory())->create();
    /** @psalm-check-type-exact $_one = \App\Models\Ledger */;

    $_many = LedgerFactory::times(2)->create();
    /** @psalm-check-type-exact $_many = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Ledger> */;
}
?>
--EXPECTF--
