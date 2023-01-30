<?php

namespace Tests\Psalm\LaravelPlugin\Support;

use Codeception\Exception\ConfigurationException;
use Codeception\Exception\ModuleRequireException;
use Codeception\Exception\TestRuntimeException;
use Codeception\Lib\ModuleContainer;
use Codeception\Module as BaseModule;
use Codeception\Module\Cli;
use Codeception\Module\Filesystem;
use Codeception\TestInterface;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use PackageVersions\Versions;
use PHPUnit\Framework\Assert;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use OutOfBoundsException;
use PHPUnit\Framework\SkippedTestError;
use RuntimeException;

use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Duplicate of https://github.com/psalm/codeception-psalm-module
 */
class Module extends BaseModule
{
    /** @var array<string,string> */
    private const VERSION_OPERATORS = [
        'newer than' => '>',
        'older than' => '<',
        'not older than' => '>=',
    ];

    private const DEFAULT_PSALM_CONFIG = "<?xml version=\"1.0\"?>\n"
    . "<psalm errorLevel=\"1\" %s>\n"
    . "  <projectFiles>\n"
    . "    <directory name=\".\"/>\n"
    . "  </projectFiles>\n"
    . "</psalm>\n";

    private const DEFAULT_MODULE_CONFIGURATION = [
        'psalm_path' => 'vendor/bin/psalm',
        'default_dir' => 'tests/_run/',
        'model_stubs_path' => 'cache/models.stubphp',
    ];

    private ?Cli $cli = null;

    private ?Filesystem $fs = null;

    private string $psalmConfig = '';

    private string $preamble = '';

    /** @var ?array<int, array{type:string,message:string}> */
    private ?array $errors = null;

    private bool $hasAutoload = false;

    private ?int $exitCode = null;

    protected ?string $output = null;

    public function __construct(ModuleContainer $moduleContainer, ?array $config = null)
    {
        assert(is_array($this->config));
        $this->config = array_merge(self::DEFAULT_MODULE_CONFIGURATION, $this->config);
        parent::__construct($moduleContainer, $config);
    }

    /** @param mixed $settings */
    public function _beforeSuite($settings = []): void
    {
        $defaultDir = $this->getDefaultDirectory();
        if (file_exists($defaultDir)) {
            if (is_dir($defaultDir)) {
                return;
            }
            unlink($defaultDir);
        }

        if (!mkdir($defaultDir, 0755, true)) {
            throw new TestRuntimeException('Failed to create dir: ' . $defaultDir);
        }
    }

    public function _before(TestInterface $test): void
    {
        $this->hasAutoload = false;
        $this->errors = null;
        $this->output = null;
        $this->exitCode = null;
        $this->config['psalm_path'] = realpath($this->config['psalm_path']);
        $this->psalmConfig = '';
        $this->fs()->cleanDir($this->getDefaultDirectory());
        $this->preamble = '';
    }

    /**
     * @param string[] $options
     */
    public function runPsalmOn(string $filename, array $options = []): void
    {
        $suppressProgress = $this->packageSatisfiesVersionConstraint('vimeo/psalm', '>=3.4.0');

        $options = array_map('escapeshellarg', $options);
        $cmd = $this->getPsalmPath()
            . ' --output-format=json '
            . ($suppressProgress ? ' --no-progress ' : ' ')
            . join(' ', $options) . ' '
            . ($filename ? escapeshellarg($filename) : '')
            . ' 2>&1';
        $this->debug('Running: ' . $cmd);
        $this->cli()->runShellCommand($cmd, false);

        $this->output = $this->cli()->output;

        $this->exitCode = $this->cli()->result;

        $this->debug(sprintf('Psalm exit code: %d', $this->exitCode));
        // $this->debug('Psalm output: ' . $this->output);
    }

    /**
     * @param string[] $options
     */
    public function runPsalmIn(string $dir, array $options = []): void
    {
        $pwd = getcwd();
        $this->fs()->amInPath($dir);

        $config = $this->psalmConfig ?: self::DEFAULT_PSALM_CONFIG;
        $config = sprintf($config, $this->hasAutoload ? 'autoloader="autoload.php"' : '');

        $this->fs()->writeToFile('psalm.xml', $config);

        $this->runPsalmOn('', $options);
        $this->fs()->amInPath($pwd);
    }

    /**
     * @param int|string $exitCode
     * @Then I see exit code :code
     */
    public function seeExitCode($exitCode): void
    {
        if (!is_int($exitCode) && !is_numeric($exitCode)) {
            throw new ConfigurationException('Feature configuration contains invalid exit code expectation.');
        }

        $exitCode = (int) $exitCode;

        if ($this->exitCode === $exitCode) {
            return;
        }

        Assert::fail("Expected exit code {$exitCode}, got {$this->exitCode}");
    }

    public function seeThisError(string $type, string $message): void
    {
        $this->parseErrors();
        if (empty($this->errors)) {
            Assert::fail("No errors! Didn't see [ $type $message ] in an empty output.\n");
        }

        foreach ($this->errors as $i => $error) {
            if (
                $this->matches($type, $error['type'])
                && $this->matches($message, $error['message'])
            ) {
                unset($this->errors[$i]);
                return;
            }
        }

        Assert::fail("Didn't see [ $type $message ] in: \n" . $this->remainingErrors());
    }

    private function matches(string $expected, string $actual): bool
    {
        $regexpDelimiter = '/';
        if ($expected[0] === $regexpDelimiter && $expected[strlen($expected) - 1] === $regexpDelimiter) {
            $regexp = $expected;
        } else {
            $regexp = $this->convertToRegexp($expected);
        }

        return (bool) preg_match($regexp, $actual);
    }

    /**
     * @Then I see no errors
     * @Then I see no other errors
     */
    public function seeNoErrors(): void
    {
        $this->parseErrors();
        if (!empty($this->errors)) {
            Assert::fail("There were errors: \n" . $this->remainingErrors());
        }
    }

    /**
     * @Then Stubs were generated for these Eloquent Models
     */
    public function stubsGeneratedForModels(TableNode $list): void
    {
        $this->fs()->openFile($this->config['model_stubs_path']);

        foreach ($list->getRows() as $line) {
            assert(is_array($line));
            $this->fs()->seeInThisFile($line[0]);
        }
    }

    private function packageSatisfiesVersionConstraint(string $package, string $versionConstraint): bool
    {
        try {
            $currentVersion = $this->getShortVersion($package);
        } catch (OutOfBoundsException $ex) {
            $this->debug(sprintf("Package %s is not installed", $package));
            return false;
        }

        $this->debug(sprintf("Current version of %s : %s", $package, $currentVersion));

        // todo: move to init/construct/before?
        $parser = new VersionParser();
        $currentVersion =  $parser->normalize($currentVersion);

        // restore pre-composer/semver:2.0 behaviour for comparison purposes
        if (preg_match('/^dev-/', $currentVersion)) {
            $currentVersion = '9999999-dev';
        }

        $result = Semver::satisfies($currentVersion, $versionConstraint);

        $this->debug("Comparing $currentVersion against $versionConstraint => " . ($result ? 'ok' : 'ko'));

        return $result;
    }

    /**
     * @deprecated
     * This method is only to maintain the public API; please use `self::haveADependencySatisfied` instead.
     */
    public function seePsalmVersionIs(string $operator, string $version): bool
    {
        return $this->packageSatisfiesVersionConstraint('vimeo/psalm', $operator . $version);
    }

    /**
     * @param string|PyStringNode $code
     *
     * @Given I have the following code preamble :code
     */
    public function haveTheFollowingCodePreamble($code): void
    {
        $this->preamble = (string) $code;
    }

    /**
     * @When I run psalm
     * @When I run Psalm
     */
    public function runPsalm(): void
    {
        $this->runPsalmIn($this->getDefaultDirectory());
    }

    /**
     * @When I run Psalm with dead code detection
     * @When I run psalm with dead code detection
     */
    public function runPsalmWithDeadCodeDetection(): void
    {
        $this->runPsalmIn($this->getDefaultDirectory(), ['--find-dead-code']);
    }

    /**
     * @When I run Psalm with taint analysis
     * @When I run psalm with taint analysis
     */
    public function runPsalmWithTaintAnalysis(): void
    {
        $this->runPsalmIn($this->getDefaultDirectory(), ['--taint-analysis', '--dump-taint-graph=../../taints.dot']);
    }

    /**
     * @When I run Psalm on :arg1
     * @When I run psalm on :arg1
     */
    public function runPsalmOnASingleFile(string $file): void
    {
        $pwd = getcwd();
        $this->fs()->amInPath($this->getDefaultDirectory());

        $config = $this->psalmConfig ?: self::DEFAULT_PSALM_CONFIG;
        $config = sprintf($config, $this->hasAutoload ? 'autoloader="autoload.php"' : '');

        $this->fs()->writeToFile('psalm.xml', $config);

        $this->runPsalmOn($file);
        $this->fs()->amInPath($pwd);
    }


    /**
     * @param string|PyStringNode $config
     * @Given I have the following config :config
     */
    public function haveTheFollowingConfig($config): void
    {
        $this->psalmConfig = (string) $config;
    }

    /**
     * @param string|PyStringNode $code
     * @Given I have the following code :code
     */
    public function haveTheFollowingCode($code): void
    {
        $file = sprintf(
            '%s/%s.php',
            rtrim($this->getDefaultDirectory(), '/'),
            sha1($this->preamble . $code)
        );

        $this->fs()->writeToFile(
            $file,
            $this->preamble . $code
        );
    }

    /**
     * @Given I have some future Psalm that supports this feature :ref
     */
    public function haveSomeFuturePsalmThatSupportsThisFeature(string $ref): void
    {
        /** @psalm-suppress InternalClass,InternalMethod */
        throw new SkippedTestError("Future functionality that Psalm has yet to support: $ref");
    }

    /**
     * @Given /I have Psalm (newer than|older than) "([0-9.]+)" \(because of "([^"]+)"\)/
     */
    public function havePsalmOfACertainVersionRangeBecauseOf(string $operator, string $version, string $reason): void
    {
        if (!isset(self::VERSION_OPERATORS[$operator])) {
            throw new TestRuntimeException("Unknown operator: $operator");
        }

        /**
         * @psalm-suppress RedundantCondition it's not redundant with older Psalm version
         * @psalm-suppress RedundantCast
         */
        $op = (string) self::VERSION_OPERATORS[$operator];

        if (!$this->packageSatisfiesVersionConstraint('vimeo/psalm', $op . $version)) {
            /** @psalm-suppress InternalClass,InternalMethod */
            throw new SkippedTestError("This scenario requires Psalm $op $version because of $reason");
        }
    }

    /**
     * @Then I see these errors
     */
    public function seeTheseErrors(TableNode $list): void
    {
        /** @psalm-suppress MixedAssignment */
        foreach (array_values($list->getRows()) as $i => $error) {
            assert(is_array($error));
            if (0 === $i) {
                continue;
            }
            $this->seeThisError((string) $error[0], (string) $error[1]);
        }
    }

    /**
     * @param string|PyStringNode $code
     * @Given I have the following code in :arg1 :arg2
     */
    public function haveTheFollowingCodeIn(string $filename, $code): void
    {
        $file = rtrim($this->getDefaultDirectory(), '/') . '/' . $filename;
        $this->fs()->writeToFile($file, (string) $code);
    }

    /**
     * @Given I have the following autoload map
     * @Given I have the following classmap
     * @Given I have the following class map
     */
    public function haveTheFollowingAutoloadMap(TableNode $list): void
    {
        $map = [];
        /** @psalm-suppress MixedAssignment */
        foreach (array_values($list->getRows()) as $i => $row) {
            assert(is_array($row));
            if (0 === $i) {
                continue;
            }
            assert(is_string($row[0]));
            assert(is_string($row[1]));
            $map[] = [$row[0], $row[1]];
        }

        $code = sprintf(
            '<?php
            spl_autoload_register(function(string $class) {
                /** @var ?array<string,string> $classes */
                static $classes = null;
                if (null === $classes) {
                    $classes = [%s];
                }
                if (array_key_exists($class, $classes)) {
                    /** @psalm-suppress UnresolvableInclude */
                    include $classes[$class];
                }
            });',
            join(
                ',',
                array_map(
                    function (array $row): string {
                        return "\n'$row[0]' => '$row[1]'";
                    },
                    $map
                )
            )
        );
        $file = rtrim($this->getDefaultDirectory(), '/') . '/' . 'autoload.php';
        $this->fs()->writeToFile($file, $code);
        $this->hasAutoload = true;
    }

    /**
     * @Given I have the :package package satisfying the :versionConstraint
     */
    public function haveADependencySatisfied(string $package, string $versionConstraint): void
    {
        if ($this->packageSatisfiesVersionConstraint($package, $versionConstraint)) {
            return;
        }

        /** @psalm-suppress InternalClass,InternalMethod */
        throw new SkippedTestError("This scenario requires $package to match $versionConstraint");
    }

    private function convertToRegexp(string $in): string
    {
        return '@' . str_replace('%', '.*', preg_quote($in, '@')) . '@';
    }

    private function cli(): Cli
    {
        if (null === $this->cli) {
            $cli = $this->getModule('Cli');
            if (!$cli instanceof Cli) {
                throw new ModuleRequireException($this, 'Needs Cli module');
            }
            $this->cli = $cli;
        }
        return $this->cli;
    }

    private function fs(): Filesystem
    {
        if (null === $this->fs) {
            $fs = $this->getModule('Filesystem');
            if (!$fs instanceof Filesystem) {
                throw new ModuleRequireException($this, 'Needs Filesystem module');
            }
            $this->fs = $fs;
        }
        return $this->fs;
    }

    private function remainingErrors(): string
    {
        $this->parseErrors();
        return (string) new TableNode(array_map(
            function (array $error): array {
                return [
                    'type' => $error['type'] ?? '',
                    'message' => $error['message'] ?? '',
                ];
            },
            $this->errors
        ));
    }

    private function getShortVersion(string $package): string
    {
        /** @psalm-suppress DeprecatedClass Versions is marked deprecated for no good reason */
        if (class_exists(InstalledVersions::class)) {
            /** @psalm-suppress UndefinedClass Composer\InstalledVersions is undefined when using Composer 1.x */
            return (string) InstalledVersions::getPrettyVersion($package);
        }

        if (class_exists(Versions::class)) {
            /**
             * @psalm-suppress ArgumentTypeCoercion Versions::getVersion() has too narrow a signature
             * @psalm-suppress RedundantCondition not redundant with older Psalm
             * @psalm-suppress RedundantCast
             */
            $version = (string) Versions::getVersion($package);
        } else {
            throw new RuntimeException(
                'Cannot determine versions. Neither of composer:2+,'
                . ' ocramius/package-version or composer/package-versions-deprecated are installed.'
            );
        }

        if (false === strpos($version, '@')) {
            throw new RuntimeException('$version must contain @');
        }

        return explode('@', $version)[0];
    }

    /**
     * @psalm-assert !null $this->errors
     */
    private function parseErrors(): void
    {
        if (null !== $this->errors) {
            return;
        }

        if (empty($this->output)) {
            $this->errors = [];
            return;
        }

        /** @psalm-suppress MixedAssignment */
        $errors = json_decode($this->output, true);

        if (null === $errors && json_last_error() !== JSON_ERROR_NONE && 0 !== $this->exitCode) {
            Assert::fail("Failed to parse output: " . $this->output . "\nError:" . json_last_error_msg());
        }

        $this->errors = array_map(
            function (array $row): array {
                return [
                    'type' => (string) $row['type'],
                    'message' => (string) $row['message'],
                ];
            },
            array_values((array)$errors)
        );
        $this->debug($this->remainingErrors());
    }

    private function getDefaultDirectory(): string
    {
        return $this->config['default_dir'];
    }

    private function getPsalmPath(): string
    {
        return $this->config['psalm_path'];
    }
}
