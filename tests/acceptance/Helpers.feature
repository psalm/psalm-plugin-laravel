Feature: helpers
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

      use Tests\Psalm\LaravelPlugin\Models\User;
      use Illuminate\Support\Optional;
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

  Scenario: head and last support
    Given I have the following code
    """
        /**
         * @return false
         */
        function empty_head()
        {
            return head([]);
        }

        /**
         * @return false
         */
        function empty_last()
        {
            return last([]);
        }

        function non_empty_head(): int
        {
            return last([1, 2, 3]);
        }

        function non_empty_last(): int
        {
            return last([1, 2, 3]);
        }
    """
    When I run Psalm
    Then I see no errors

  Scenario: optional support
    Given I have the following code
    """
        function test(?Throwable $user): ?string
        {
            return optional($user)->getMessage();
        }
    """
    When I run Psalm
    Then I see no errors

  Scenario: logger support
    Given I have the following code
    """
        /**
        * @return null
        */
        function args()
        {
            return logger('this should return void');
        }

        function no_args(): \Illuminate\Log\LogManager
        {
          return logger();
        }
    """
    When I run Psalm
    Then I see no errors
