--FILE--
<?php declare(strict_types=1);

namespace Laravel\Sanctum\Contracts {
    interface HasAbilities
    {
    }
}

namespace Laravel\Sanctum {
    use Laravel\Sanctum\Contracts\HasAbilities;

    class PersonalAccessToken implements HasAbilities
    {
    }

    /** @template TToken of HasAbilities = PersonalAccessToken */
    trait HasApiTokens
    {
        /** @return TToken */
        public function currentAccessToken()
        {
            throw new \LogicException();
        }
    }
}

namespace Application {
    use Laravel\Sanctum\Contracts\HasAbilities;
    use Laravel\Sanctum\HasApiTokens;
    use Laravel\Sanctum\PersonalAccessToken;

    final class CustomToken implements HasAbilities
    {
    }

    trait UsesSanctumTokens
    {
        use HasApiTokens;
    }

    trait UsesNestedSanctumTokens
    {
        use UsesSanctumTokens;
    }

    trait UsesCustomSanctumToken
    {
        /** @use HasApiTokens<CustomToken> */
        use HasApiTokens;
    }

    /** @template TUnrelated */
    trait RequiresTemplateArgument
    {
    }

    final class DirectUser
    {
        use HasApiTokens;
    }

    final class WrappedUser
    {
        use UsesSanctumTokens;
    }

    final class NestedWrappedUser
    {
        use UsesNestedSanctumTokens;
    }

    final class ExplicitUser
    {
        use UsesCustomSanctumToken;
    }

    final class BoundaryUser
    {
        use HasApiTokens;
        use RequiresTemplateArgument;
    }

    $_directToken = (new DirectUser())->currentAccessToken();
    /** @psalm-check-type-exact $_directToken = PersonalAccessToken */;

    $_wrappedToken = (new WrappedUser())->currentAccessToken();
    /** @psalm-check-type-exact $_wrappedToken = PersonalAccessToken */;

    $_nestedWrappedToken = (new NestedWrappedUser())->currentAccessToken();
    /** @psalm-check-type-exact $_nestedWrappedToken = PersonalAccessToken */;

    $_explicitToken = (new ExplicitUser())->currentAccessToken();
    /** @psalm-check-type-exact $_explicitToken = CustomToken */;
}
?>
--EXPECTF--
MissingTemplateParam on line %d: Application\BoundaryUser has missing template params when extending Application\RequiresTemplateArgument, expecting 1
