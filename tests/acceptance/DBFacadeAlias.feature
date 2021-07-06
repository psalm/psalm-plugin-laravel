Feature: DB facade alias
  The DB facade is supported

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="false">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: call the DB facade alias
    Given I have the following code
    """
    <?php declare(strict_types=1);

    namespace Tests\Psalm\LaravelPlugin\Sandbox;

    function test(): void {
      \DB::raw(1);
    }
    """
    When I run Psalm
    Then I see no errors
