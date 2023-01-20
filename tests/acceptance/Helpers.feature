Feature: helpers
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

  Scenario: cookie support
    Given I have the following code
    """
        function two_args_to_set_cookie(): \Symfony\Component\HttpFoundation\Cookie
        {
            return cookie('some.key', 42);
        }

        function single_arg_to_get_cookie(): \Symfony\Component\HttpFoundation\Cookie
        {
            return cookie('some.key');
        }

        function no_args(): \Illuminate\Cookie\CookieJar
        {
          return cookie();
        }
    """
    When I run Psalm
    Then I see no errors

  Scenario: session support
    Given I have the following code
    """
        function scalar_arg_to_get_value_from_session(): mixed
        {
            return session('some-key');
        }

        function array_arg_to_set_session(): null
        {
            return session(['some-key' => 42]);
        }

        function no_args(): \Illuminate\Session\SessionManager
        {
          return session();
        }
    """
    When I run Psalm
    Then I see no errors

  Scenario: rescue support
    Given I have the following code
    """
        function with_default_of_the_same_type(): int
        {
            return rescue(fn (): int => 0, 42);
        }

        function without_default(): ?int
        {
            return rescue(fn (): int => 0);
        }
    """
    When I run Psalm
    Then I see no errors

  Scenario: retry support
    Given I have the following code
    """
        function retry_has_callable_with_return_type(): int
        {
            return retry(2, fn (): int => 42);
        }

        function retry_has_callable_without_return_type(): int
        {
            return retry(2, fn () => 42);
        }
    """
    When I run Psalm
    Then I see no errors

  Scenario: tap support
    Given I have the following code
    """
        function tap_without_callback(): \DateTime
        {
            return tap(new \DateTime);
        }

        function tap_accepts_callable(): \DateTime
        {
            return tap(new \DateTime, fn (\DateTime $now) => null);
        }
    """
    When I run Psalm
    Then I see no errors
