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
     * Static-fallback (b): bare factory, no `@extends`, no `$model`. The model
     * is derived from the shortname convention
     * (`Database\Factories\LedgerFactory` -> `App\Models\Ledger`). This is the
     * exact shape #780 reports as leaking `MissingTemplateParam` on stock
     * Laravel projects.
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

namespace App\Sandbox\ShortName {
    use Database\Factories\LedgerFactory;

    $_one = (new LedgerFactory())->create();
    /** @psalm-check-type-exact $_one = \App\Models\Ledger */;

    // count(N) plurality still threads through the injected TModel.
    $_many = (new LedgerFactory())->count(3)->create();
    /** @psalm-check-type-exact $_many = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Ledger> */;
}
?>
--EXPECTF--
