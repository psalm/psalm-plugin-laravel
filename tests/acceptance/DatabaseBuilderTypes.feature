Feature: Database Builder types
  Illuminate\Database\Builder has type support

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

  Scenario: Models can call database query builder instance methods
    Given I have the following code
    """
    <?php declare(strict_types=1);

    use Tests\Psalm\LaravelPlugin\Models\User;

    final class UserRepository
    {
        /**
        * @param \Illuminate\Database\Eloquent\Builder<User> $builder
        */
        public function firstFromDatabaseBuilderInstance(\Illuminate\Database\Eloquent\Builder $builder): ?User {
          return $builder->first();
        }
    }
    """
    When I run Psalm
    Then I see no errors
