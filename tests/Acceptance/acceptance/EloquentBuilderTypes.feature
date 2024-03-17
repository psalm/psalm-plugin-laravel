Feature: Eloquent Builder types
  Illuminate\Database\Eloquent\Builder has type support

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1" findUnusedCode="false">
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
      use App\Models\User;
      """

  Scenario: Errors on calling Model method that is not exist even through magic
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
        /** @return Builder<User> */
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
