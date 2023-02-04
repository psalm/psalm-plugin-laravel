Feature: Eloquent Model types
  Illuminate\Database\Eloquent\Model has type support

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

      use App\Models\User;
      """

  Scenario: Model scope support
    Given I have the following code
    """

      function test(): \Illuminate\Database\Eloquent\Collection
      {
        return User::active()->get();
      }

    """
    When I run Psalm
    Then I see no errors

  Scenario: find or fail support
    Given I have the following code
    """

      function test(): User
      {
        return User::findOrFail(1);
      }

    """
    When I run Psalm
    Then I see no errors

