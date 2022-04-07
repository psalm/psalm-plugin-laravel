Feature: url()
  The global url helper will return the correct type depending on args

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
        public function getUrlGenerator(): \Illuminate\Contracts\Routing\UrlGenerator {
          return url();
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
        public function getUrl(): string {
          return url('example.com');
        }
      }
    """
    When I run Psalm
    Then I see no errors
