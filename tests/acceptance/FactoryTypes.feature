Feature: Factory Types
  Model factories have type support

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

  Scenario:
    Given I have the following code
    """
    <?php declare(strict_types=1);

    use Tests\Psalm\LaravelPlugin\Models\User;

    class FactoryTest {
      /**
      * @return \Illuminate\Database\Eloquent\FactoryBuilder<User, 1>
      */
      public function getFactory(): \Illuminate\Database\Eloquent\FactoryBuilder
      {
        return factory(User::class);
      }

      /**
      * @return \Illuminate\Database\Eloquent\FactoryBuilder<User, 2>
      */
      public function getFactoryForTwo(): \Illuminate\Database\Eloquent\FactoryBuilder
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
      * @return \Illuminate\Database\Eloquent\Collection<User>
      */
      public function createUsers(): \Illuminate\Database\Eloquent\Collection
      {
        return factory(User::class, 2)->create();
      }

      /**
      * @return \Illuminate\Database\Eloquent\Collection<User>
      */
      public function createUsersWithNameAttribute(): \Illuminate\Database\Eloquent\Collection
      {
        return factory(User::class, 'new name', 2)->create();
      }
    }
    """
    When I run Psalm
    Then I see no errors
