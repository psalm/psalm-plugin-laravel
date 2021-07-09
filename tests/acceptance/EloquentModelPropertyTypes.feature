Feature: Eloquent Model property types
  Illuminate\Database\Eloquent\Model have property type support

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true" usePhpDocPropertiesWithoutMagicCall="true">
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

      use Tests\Psalm\LaravelPlugin\Models\Secret;
      use Tests\Psalm\LaravelPlugin\Models\User;
      """

  Scenario: Property annotation with scalar type
    Given I have the following code
    """
      function test(User $user): string
      {
          return $user->id;
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Property annotation with imported type
    Given I have the following code
    """
      function test(User $user): ?\Carbon\CarbonInterface
      {
        return $user->email_verified_at;
      }
    """
    When I run Psalm
    Then I see no errors


  Scenario: Inherited property annotation
    Given I have the following code
    """
      function test(Secret $secret): \Ramsey\Uuid\UuidInterface
      {
        return $secret->uuid;
      }
    """
    When I run Psalm
    Then I see no errors
