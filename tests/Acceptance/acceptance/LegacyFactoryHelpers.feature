Feature: Legacy Factory helpers
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
      """

  Scenario: factory() support
    Given I have the following code
    """
        function test_factory_returns_model(): User
        {
            return factory(\App\Models\User::class)->create();
        }

        function test_factory_returns_model_with_explicit_count(): User
        {
            return factory(\App\Models\User::class, 1)->create();
        }

        /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> **/
        function test_factory_returns_collection()
        {
            return factory(\App\Models\User::class, 2)->create();
        }

        function test_factory_with_times_1_returns_model(): User
        {
            return factory(\App\Models\User::class)->times(1)->create();
        }

        /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> **/
        function test_factory_with_times_2_returns_collection()
        {
            return factory(\App\Models\User::class)->times(2)->create();
        }
    """
    When I run Psalm
    Then I see no errors
