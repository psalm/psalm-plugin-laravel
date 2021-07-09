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

  Scenario:
    Given I have the following code
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
