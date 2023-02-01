Feature: Facades

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

  Scenario: Storage facade
    Given I have the following code
    """
      function test_storage_disk_call_on_namespaced_facade(): \Illuminate\Contracts\Filesystem\Filesystem {
        return \Illuminate\Support\Facades\Storage::disk('resources');
      }

      function test_storage_disk_call_on_root_namespace_facade(): \Illuminate\Contracts\Filesystem\Filesystem {
        return \Storage::disk('resources');
      }
    """
    When I run Psalm
    Then I see no errors
