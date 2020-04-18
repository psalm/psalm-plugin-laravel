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
