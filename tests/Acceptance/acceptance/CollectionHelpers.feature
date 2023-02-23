Feature: Collection helpers
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
    """

  Scenario: head() and last() support
    Given I have the following code
    """
    /** @return false */
    function empty_head()
    {
        return head([]);
    }

    /** @return false */
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

  Scenario: value() support
    Given I have the following code
    """
    function if_first_value_arg_is_closure_then_closure_result_returned(): int
    {
        return value(
          Closure::fromCallable(fn() => 42),
          'some', 'args', 'to', 'pass', 'to', 'closure'
        );
    }

    function if_first_value_arg_is_not_closure_then_mixed_expected(): mixed
    {
        return value(
          42,
          'some', 'args', 'to', 'pass', 'to', 'closure'
        );
    }
    """
  When I run Psalm
  Then I see no errors
