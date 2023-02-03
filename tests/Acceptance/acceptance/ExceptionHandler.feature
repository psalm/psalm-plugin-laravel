Feature: ExceptionHandler
  If an exception is thrown while psalm analyzes the codebase, the status  code returned will not be 0

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
          <plugin filename="somefile.php" />
          <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: Laravel exception handler is not registered as plugin’s default exception handler
    Given I have the following code
    """
    <?php
      use Psalm\CodeLocation;
      use Psalm\Context;
      use Psalm\StatementsSource;
      use Psalm\Plugin\PluginEntryPointInterface;
      use Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface;

      class FailingPlugin implements PluginEntryPointInterface, FunctionReturnTypeProviderInterface {
        public function __invoke(\Psalm\Plugin\RegistrationInterface $registration, ?\SimpleXMLElement $config = null) {
          return;
        }

        public static function getFunctionIds(): array
        {
            return ['foo'];
        }

        public static function getFunctionReturnType(StatementsSource $statements_source, string $function_id, array $call_args, Context $context, CodeLocation $code_location): ?Union
        {
            if ($function_id === 'foo') {
              throw new \InvalidArgumentException('expected runtime exception');
            }

            return null;
        }
      }

      function foo() {
      }

      foo();
    """
    When I run Psalm
    Then I see exit code 1
