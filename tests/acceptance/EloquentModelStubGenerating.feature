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
      | final class Car extends \Eloquent |
      | final class Comment extends \Eloquent |
      | final class Image extends \Eloquent |
      | final class Mechanic extends \Eloquent |
      | final class Phone extends \Eloquent |
      | final class Post extends \Eloquent |
      | final class Role extends \Eloquent |
      | final class Secret extends \Eloquent |
      | final class Tag extends \Eloquent |
      | final class User extends \Eloquent |
      | final class Video extends \Eloquent |