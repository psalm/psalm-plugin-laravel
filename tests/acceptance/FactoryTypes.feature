Feature: factory()
  The global factory function has type support

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
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

    use Illuminate\Database\Eloquent\Collection;
    use Illuminate\Database\Eloquent\FactoryBuilder;
    use Tests\Psalm\LaravelPlugin\Models\User;
    """

  Scenario: can use factory helper in Laravel 6.x and 7.x
    Given I have the "laravel/framework" package satisfying the "6.* || 7.*"
    And I have the following code
    """
    class FactoryTest {
      /**
       * @return FactoryBuilder<User, 1>
       */
      public function getFactory(): FactoryBuilder
      {
        return factory(User::class);
      }

      /**
       * @return FactoryBuilder<User, 2>
       */
      public function getFactoryForTwo(): FactoryBuilder
      {
        return factory(User::class, 2);
      }

      public function makeUser(): User
      {
        return factory(User::class)->make();
      }

      public function createUser(): User
      {
        return factory(User::class)->create();
      }

      /**
       * @return Collection<User>
       */
      public function createUsers(): Collection
      {
        return factory(User::class, 2)->create();
      }

      /**
       * @return Collection<User>
       */
      public function createUsersWithNameAttribute(): Collection
      {
        return factory(User::class, 'new name', 2)->create();
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: cannot use factory helper in Laravel 8.x and later
    Given I have the "laravel/framework" package satisfying the ">= 8.0"
    And I have the following code
    """
    class FactoryTest {
      /**
       * @return FactoryBuilder<User, 1>
       */
      public function getFactory(): FactoryBuilder
      {
        return factory(User::class);
      }
    }
    """
    When I run Psalm
    Then I see these errors
      | Type  | Message |
      | UndefinedFunction | Function factory does not exist |
