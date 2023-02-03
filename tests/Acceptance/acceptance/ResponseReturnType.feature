Feature: response()
  The global response helper will return the correct type depending on args

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

  Scenario: response called with no arguments returns an instance of response factory
    Given I have the following code
    """
    <?php
      class Foo {
        public function bar(): \Illuminate\Contracts\Routing\ResponseFactory {
          return response();
        }
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: response called with arguments returns an instance of response
    Given I have the following code
    """
    <?php
      class Foo {
        public function bar(): \Illuminate\Http\Response {
          return response('ok');
        }
      }
    """
    When I run Psalm
    Then I see no errors
