Feature: Path helpers
  The global path helpers will return the correct path

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
    And I have the following code preamble
      """
      <?php declare(strict_types=1);

      """

    Scenario: base path can be resolved
      Given I have the following code
      """
      require_once base_path('routes/console.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: basePath can be resolved from application instance
      Given I have the following code
      """
      require_once app()->basePath('routes/console.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: app path can be resolved
      Given I have the following code
      """
      require_once app_path('model.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: path can be resolved from application instance
      Given I have the following code
      """
      require_once app()->path('model.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: config path can be resolved
      Given I have the following code
      """
      require_once config_path('file.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: path can be resolved from application instance
      Given I have the following code
      """
      require_once app()->configPath('file.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: database path can be resolved
      Given I have the following code
      """
      require_once database_path('migration.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: databasePath can be resolved from application instance
      Given I have the following code
      """
      require_once app()->databasePath('migration.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: public path can be resolved
      Given I have the following code
      """
      require_once public_path('file.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: public path can be resolved from application instance
      Given I have the following code
      """
      require_once app()->make('path.public');
      """
      When I run Psalm
      Then I see no errors

    Scenario: resource path can be resolved
      Given I have the following code
      """
      require_once resource_path('file.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: resource path can be resolved from application instance
      Given I have the following code
      """
      require_once app()->resourcePath('file.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: storage path can be resolved
      Given I have the following code
      """
      require_once storage_path('file.php');
      """
      When I run Psalm
      Then I see these errors
        | Type                  | Message |
        | MissingFile | Cannot find file |

    Scenario: storage path can be resolved from application instance
      Given I have the following code
      """
      require_once app()->make('path.storage');
      """
      When I run Psalm
      Then I see no errors
