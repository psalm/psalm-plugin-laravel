Feature: helpers
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
      """

  Scenario: env can be pulled off the app
    Given I have the following code
    """
      if (app()->environment('production')) {
        // do something
      }
    """
    When I run Psalm
    Then I see no errors
