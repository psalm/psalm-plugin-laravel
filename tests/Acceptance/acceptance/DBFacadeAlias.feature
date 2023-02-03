Feature: DB facade alias
  The DB facade is supported

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="2" reportMixedIssues="false">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: call the DB facade alias [ Laravel 9 ]
    Given I have the "laravel/framework" package satisfying the "^9.0"
    And I have the following code
    """
    <?php declare(strict_types=1);

    namespace Tests\Psalm\LaravelPlugin\Sandbox;

    function test_db_raw(): \Illuminate\Database\Query\Expression {
      return \DB::raw(1);
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: call the DB facade alias [ Laravel 10 ]
    Given I have the "laravel/framework" package satisfying the "^10.0"
    And I have the following code
    """
    <?php declare(strict_types=1);

    namespace Tests\Psalm\LaravelPlugin\Sandbox;

    function test_db_raw(): \Illuminate\Contracts\Database\Query\Expression {
      return \DB::raw(1);
    }
    """
    When I run Psalm
    Then I see no errors
