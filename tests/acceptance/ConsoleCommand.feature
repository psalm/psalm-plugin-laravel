Feature: Console Command types
  Illuminate\Console\Command have type support

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

  Scenario: "artisan make:command Example"
    Given I have the following code
      """
      <?php declare(strict_types=1);
      namespace App\Console\Commands;

      use Illuminate\Console\Command;

      class Example extends Command
      {
          /**
           * The name and signature of the console command.
           *
           * @var string
           */
          protected $signature = 'command:name';

          /**
           * The console command description.
           *
           * @var string
           */
          protected $description = 'Command description';

          /**
           * Create a new command instance.
           *
           * @return void
           */
          public function __construct()
          {
              parent::__construct();
          }

          /**
           * Execute the console command.
           *
           * @return int
           */
          public function handle()
          {
              return 0;
          }
      }
      """
    When I run Psalm
    Then I see no errors
