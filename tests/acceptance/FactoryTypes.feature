Feature: factory()
  The global factory function has type support

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

    use Illuminate\Database\Eloquent\Collection;
    use Illuminate\Database\Eloquent\FactoryBuilder;
    use Tests\Psalm\LaravelPlugin\Models\User;
    """

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
