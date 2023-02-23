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

      use App\Models\User;
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

  Scenario: cache support
    Given I have the following code
    """
        function test_cache_call_without_args_should_return_CacheManager(): \Illuminate\Cache\CacheManager
        {
            return cache();
        }

        function test_cache_call_with_string_as_arg_should_return_string(): mixed
        {
            return cache('key'); // get value
        }

        function test_cache_call_with_array_as_arg_should_return_bool(): bool
        {
          return cache(['key' => 42]); // set value
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

  Scenario: throw_if support
    Given I have the following code
    """
        /** @return false */
        function throw_if_with_bool_arg(bool $var): bool
        {
            throw_if($var);
            return $var;
        }

        /** @return ''|'0' **/
        function throw_if_with_string_arg(string $var): string
        {
            throw_if($var);
            return $var;
        }

        /** @return list<never> **/
        function throw_if_with_array_arg(array $var): array
        {
            throw_if($var);
            return $var;
        }

        /** @return 0 **/
        function throw_if_with_int_arg(int $var): int
        {
            throw_if($var);
            return $var;
        }

        /** @return 0.0 **/
        function throw_if_with_float_arg(float $var): float
        {
            throw_if($var);
            return $var;
        }

        /** @return true */
        function throw_unless_with_bool_arg(bool $var): bool
        {
            throw_unless($var);
            return $var;
        }

        /** @return non-empty-string **/
        function throw_unless_with_string_arg(string $var): string
        {
            throw_unless($var);
            return $var;
        }

        /** @return non-empty-array **/
        function throw_unless_with_array_arg(array $var): array
        {
            throw_unless($var);
            return $var;
        }

        function throw_unless_with_int_arg(int $var): int
        {
            throw_unless($var);
            return $var;
        }

        function throw_unless_with_float_arg(float $var): float
        {
            throw_unless($var);
            return $var;
        }
    """
    When I run Psalm
    Then I see no errors

  Scenario: class_uses_recursive support
    Given I have the following code
    """
        /** @return array<trait-string|class-string, trait-string|class-string> **/
        function test_class_uses_recursive(): array {
          return class_uses_recursive(\App\Models\User::class);
        }
    """
    When I run Psalm
    Then I see no errors

  Scenario: trait_uses_recursive support
    Given I have the following code
    """
        trait CustomSoftDeletes {
          use \Illuminate\Database\Eloquent\SoftDeletes;
        }

        /** @return array<trait-string, trait-string> **/
        function test_trait_uses_recursive(): array {
          return trait_uses_recursive(CustomSoftDeletes::class);
        }
    """
    When I run Psalm
    Then I see no errors
