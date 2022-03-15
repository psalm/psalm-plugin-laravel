Feature: abort_if()
  The global abort_if helper is supported

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
      """

  Scenario: abort asserts not null
    Given I have the following code
    """
      /**
      * @param string|null $nullable
      */
      function test($nullable): string {
        if (!$nullable) {
            abort(422);
        }

        return $nullable;
      }
    """

  Scenario: abort_if asserts not null
    Given I have the following code
    """
      /**
      * @param string|null $nullable
      */
      function abortIfNullable($nullable): string {
        abort_if(is_null($nullable), 422);

        return $nullable;
      }
    """
    When I run Psalm
    Then I see no errors
