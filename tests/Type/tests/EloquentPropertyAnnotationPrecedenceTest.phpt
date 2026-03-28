--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Phone;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * @property annotations on a model should take precedence over plugin inference.
 *
 * User model declares:
 *   @property string $id                                         - overrides migration-inferred int
 *   @property Phone|null $phone                                  - overrides relationship-inferred Phone
 *   @property non-empty-string $first_name_using_legacy_accessor - overrides accessor-inferred string
 */

/** Column: @property string $id wins over migration-inferred int<0, max> */
function test_property_annotation_overrides_column_type(User $user): string
{
    /** @psalm-check-type-exact $id = string */
    $id = $user->id;
    return $id;
}

/** Relationship: @property Phone|null $phone wins over relationship-inferred Phone */
function test_property_annotation_overrides_relationship_type(User $user): ?Phone
{
    /** @psalm-check-type-exact $phone = Phone|null */
    $phone = $user->phone;
    return $phone;
}

/** Accessor: @property non-empty-string $first_name_using_legacy_accessor wins over accessor-inferred string */
function test_property_annotation_overrides_accessor_type(User $user): string
{
    /** @psalm-check-type-exact $name = non-empty-string */
    $name = $user->first_name_using_legacy_accessor;
    return $name;
}
?>
--EXPECTF--
