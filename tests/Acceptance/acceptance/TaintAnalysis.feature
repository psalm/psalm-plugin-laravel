Feature: Taint Analysis
  Want to check that taint analysis works properly

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="false">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: request input is taint for Builder::raw
    Given I have the following code
    """
    <?php
     function test_db_raw(\Illuminate\Http\Request $request) {
        $query_builder = new \Illuminate\Database\Query\Builder();
        $user_input = $request->input('foo');
        $query_builder->raw($user_input);
     }
    """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message |
      | TaintedSql  | Detected tainted SQL |

  Scenario: request input is taint for HTTP Response content
    Given I have the following code
    """
    <?php
     function test_db_raw(\Illuminate\Http\Request $request) {
        $taint_input = $request->input('foo');

        return new \Illuminate\Http\Response($taint_input);
     }
    """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message |
      | TaintedHtml | Detected tainted HTML |
