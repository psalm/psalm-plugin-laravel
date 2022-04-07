Feature: redirect()
  The global redirect helper will return the correct type depending on args

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

  Scenario: Unknown Scenario
    Given I have the following code
    """
    <?php
      class Foo {
        public function bar(): \Illuminate\Routing\Redirector {
          return redirect();
        }
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Unknown Scenario
    Given I have the following code
    """
    <?php
      class Foo {
        public function bar(): Illuminate\Http\RedirectResponse {
          return redirect('foo');
        }
      }
    """
    When I run Psalm
    Then I see no errors
