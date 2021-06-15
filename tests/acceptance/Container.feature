Feature: Container
  The laravel container can be resolved, and it has types

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

  Scenario: Application interface does not error
    Given I have the following code
    """
    <?php
      use App\Jobs\PullContact;
      use Illuminate\Support\ServiceProvider;

      class AppServiceProvider extends ServiceProvider {
        public function register() {
          $this->app->foo("a", "b");
        }
      }
    """
    When I run Psalm
    Then I see these errors
      | Type  | Message |
      | UndefinedInterfaceMethod | Method Illuminate\Contracts\Foundation\Application::foo does not exist |

  Scenario: the container resolves correct types
    Given I have the following code
    """
    <?php
      class Foo {
        public function applicationResolvesTypes(): \Illuminate\Routing\Redirector
        {
          $application = new \Illuminate\Foundation\Application();
          return $application->make(\Illuminate\Routing\Redirector::class);
        }
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: the application interface supports array access for container
    Given I have the following code
    """
    <?php
      class Foo {
        public function applicationResolvesTypes(Illuminate\Contracts\Foundation\Application $app): \Illuminate\Routing\Redirector
        {
          return $app[\Illuminate\Routing\Redirector::class];
        }
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: the app function helper resolves correct types
    Given I have the following code
    """
    <?php
      class Foo {
        public function appHelperGetContainer(): \Illuminate\Contracts\Foundation\Application {
          return app();
        }

        public function appHelperResolvesTypes(): \Illuminate\Routing\Redirector
        {
          return app(\Illuminate\Routing\Redirector::class);
        }
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: the resolve function helper resolves correct types
    Given I have the following code
    """
    <?php
      class Foo {

        public function resolveHelperResolvesTypes(): \Illuminate\Routing\Redirector
        {
          return resolve(\Illuminate\Routing\Redirector::class);
        }
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: app helper can be chained with make / makeWith
    Given I have the following code
    """
    <?php
      function testMake(): \Illuminate\Routing\Redirector {
        return app()->make(\Illuminate\Routing\Redirector::class);
      }

      function testMakeWith(): \Illuminate\Routing\Redirector {
        return app()->makeWith(\Illuminate\Routing\Redirector::class);
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: container can resolve aliases
    Given I have the following code
    """
    <?php
      function testMake(): \Illuminate\Log\LogManager {
        return app()->make('log');
      }

      function testMakeWith(): \Illuminate\Log\LogManager {
        return app()->makeWith('log');
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: container cannot resolve unknown aliases
    Given I have the following code
    """
    <?php

      function testMakeWith(): \Illuminate\Log\LogManager {
        return app()->makeWith('logg');
      }
    """
    When I run Psalm
    Then I see exit code 2
