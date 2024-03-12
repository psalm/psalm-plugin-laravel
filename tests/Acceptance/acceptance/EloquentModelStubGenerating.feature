Feature: Eloquent Model stub generating
  Stubs for Eloquent Models were correctly generated

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1" usePhpDocPropertiesWithoutMagicCall="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: Generate stubs for Eloquent Models
      When I run Psalm
    Then Stubs were generated for these Eloquent Models
      | class Car extends \Eloquent |
      | class Comment extends \Eloquent |
      | class Image extends \Eloquent |
      | class Mechanic extends \Eloquent |
      | class Phone extends \Eloquent |
      | class Post extends \Eloquent |
      | class Role extends \Eloquent |
      | class Secret extends \Eloquent |
      | class Tag extends \Eloquent |
      | class User extends \Eloquent |
      | class Video extends \Eloquent |
