Feature: Eloquent Builder types
  Illuminate\Database\Eloquent\Builder has type support

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
        </plugins>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php declare(strict_types=1);
      namespace Tests\Psalm\LaravelPlugin\Sandbox;

      use Illuminate\Database\Eloquent\Builder;
      use Illuminate\Database\Eloquent\Collection;
      use Tests\Psalm\LaravelPlugin\Models\User;
      """

  Scenario: Models can call eloquent query builder instance methods
    Given I have the following code
    """
    final class UserRepository
    {

        /**
        * @return Builder<User>
        */
        public function getNewQuery(): Builder
        {
          return User::query();
        }

        /**
        * @return Builder<User>
        */
        public function getNewModelQuery(): Builder
        {
          return (new User())->newModelQuery();
        }

        /**
        * @param Builder<User> $builder
        */
        public function firstOrFailFromBuilderInstance(Builder $builder): User {
          return $builder->firstOrFail();
        }

        /**
        * @param Builder<User> $builder
        */
        public function findOrFailFromBuilderInstance(Builder $builder): User {
          return $builder->findOrFail(1);
        }

        /**
        * @param Builder<User> $builder
        * @return Collection<User>
        */
        public function findMultipleOrFailFromBuilderInstance(Builder $builder): Collection {
          return $builder->findOrFail([1, 2]);
        }

        /**
        * @param Builder<User> $builder
        */
        public function findOne(Builder $builder): ?User {
          return $builder->find(1);
        }

        /**
        * @param Builder<User> $builder
        */
        public function findViaArray(Builder $builder): Collection {
          return $builder->find([1]);
        }

        /**
        * @return Builder<User>
        */
        public function getWhereBuilderViaInstance(array $attributes): Builder {
          return (new User())->where($attributes);
        }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: can call static methods on model
    Given I have the following code preamble
    """
    <?php declare(strict_types=1);

    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Collection;
    """
    And I have the following code
    """

    final class User extends \Illuminate\Database\Eloquent\Model {
      protected $table = 'users';
    };

    final class UserRepository
    {

        /**
        * @return Builder<User>
        */
        public function getWhereBuilderViaStatic(array $attributes): Builder
        {
          return User::where($attributes);
        }

        /**
        * @psalm-return Collection<User>
        */
        public function getWhereViaStatic(array $attributes): Collection
        {
          return User::where($attributes)->get();
        }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Unknown Scenario
    Given I have the following code preamble
    """
    <?php declare(strict_types=1);

    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Model;
    """
    And I have the following code
    """
    final class User extends Model {
      protected $table = 'users';
    };

    final class UserRepository
    {
        /**
        * @return Builder<User>
        */
        public function test_failure(): Builder
        {
          return User::fakeQueryMethodThatDoesntExist();
        }
    }
    """
    When I run Psalm
    Then I see these errors
      | Type  | Message |
      | MixedInferredReturnType | Could not verify return type 'Illuminate\Database\Eloquent\Builder<User>' for UserRepository::test_failure |
      | MixedReturnStatement    | Could not infer a return type                                                                              |

  Scenario: can call methods on underlying query builder
    Given I have the following code
    """
    /**
    * @psalm-param Builder<User> $builder
    * @psalm-return Builder<User>
    */
    function test(Builder $builder): Builder {
      return $builder->orderBy('id', 'ASC');
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: can call firstOrNew and firstOrCreate without parameters in Laravel 8.x
    Given I have the "laravel/framework" package satisfying the ">= 8.0"
    And I have the following code
    """
    /**
    * @psalm-param Builder<User> $builder
    * @psalm-return User
    */
    function test_firstOrCreate(Builder $builder): User {
      return $builder->firstOrCreate();
    }

    /**
    * @psalm-param Builder<User> $builder
    * @psalm-return User
    */
    function test_firstOrNew(Builder $builder): User {
      return $builder->firstOrNew();
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: can call whereDate with \DateTimeInterface|string|null
    Given I have the following code
    """
    /**
    * @psalm-param Builder $builder
    * @psalm-return Builder
    */
    function test_whereDateWithDateTimeInterface(Builder $builder): Builder {
      return $builder->whereDate('created_at', '>', new \DateTimeImmutable());
    }

    /**
    * @psalm-param Builder $builder
    * @psalm-return Builder
    */
    function test_whereDateWithString(Builder $builder): Builder {
      return $builder->whereDate('created_at', '>', (new \DateTimeImmutable())->format('d/m/Y'));
    }

    /**
    * @psalm-param Builder $builder
    * @psalm-return Builder
    */
    function test_whereDateWithNull(Builder $builder): Builder {
      return $builder->whereDate('created_at', '>', null);
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: can not call whereDate with incompatible type
    Given I have the following code
    """
    /**
    * @psalm-param Builder $builder
    * @psalm-return Builder
    */
    function test_whereDateWithInt(Builder $builder): Builder {
      return $builder->whereDate('created_at', '>', 1);
    }
    """
    When I run Psalm
    Then I see these errors
      | Type  | Message |
      | InvalidScalarArgument | Argument 3 of Illuminate\Database\Eloquent\Builder::whereDate expects DateTimeInterface\|null\|string, 1 provided |

  Scenario: can call count on the builder instance
    Given I have the following code
    """
    /**
    * @psalm-param Builder $builder
    * @psalm-return int
    */
    function test_whereDateWithInt(Builder $builder): int {
      return $builder->count();
    }
    """
    When I run Psalm
    Then I see no errors
