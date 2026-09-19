<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof that a template's `{{-- @var --}}` / `@props` contract is enforced at the
 * `view()` call sites that feed it. A real `vendor/bin/psalm` run is the only way to pin it: the
 * contract only exists after the Blade bootstrap has compiled the fixture's templates, and the
 * check reads argument types Psalm infers during analysis.
 *
 * phpt type tests cannot cover this: they analyze against Testbench's view root inside `vendor/`,
 * and no `tests/Type/psalm-*.xml` config enables Blade.
 */
#[CoversClass(ViewContractHandler::class)]
#[CoversClass(ViewCallChain::class)]
#[CoversClass(ContractRegistry::class)]
final class ViewContractValidationTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/ViewContract';

    private const SHADOW_DIR = self::FIXTURE . '/.cache/blade-shadows';

    private const MISSING = 'MissingViewVariable';

    private const WRONG_TYPE = 'InvalidViewVariableType';

    protected function setUp(): void
    {
        $this->deleteShadowDir();
    }

    protected function tearDown(): void
    {
        $this->deleteShadowDir();
    }

    private function deleteShadowDir(): void
    {
        if (!\is_dir(self::SHADOW_DIR)) {
            return;
        }

        foreach (\array_diff(\scandir(self::SHADOW_DIR) ?: [], ['.', '..']) as $entry) {
            \unlink(self::SHADOW_DIR . '/' . $entry);
        }

        \rmdir(self::SHADOW_DIR);
    }

    /**
     * Contract issues only, keyed by the fixture file that caused them — the fixture reports plenty
     * of unrelated issues at errorLevel 1 and the shadows add more.
     *
     * @return list<array{type: string, file: string, message: string}>
     */
    private function contractIssues(string $config): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            self::FIXTURE,
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports issues on purpose.
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$process->getOutput()}\n{$process->getErrorOutput()}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $type = (string) $issue['type'];

            if ($type !== self::MISSING && $type !== self::WRONG_TYPE) {
                continue;
            }

            $issues[] = [
                'type' => $type,
                'file' => \basename((string) $issue['file_name']),
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * @param list<array{type: string, file: string, message: string}> $issues
     *
     * @return list<array{type: string, file: string, message: string}>
     */
    private function forFile(array $issues, string $file): array
    {
        return \array_values(\array_filter($issues, static fn(array $issue): bool => $issue['file'] === $file));
    }

    #[Test]
    public function a_declared_variable_absent_from_the_data_array_is_reported(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'MissingVariable.php');

        $this->assertCount(2, $reported, \var_export($issues, true));

        foreach ($reported as $issue) {
            $this->assertSame(self::MISSING, $issue['type']);
        }

        $messages = \implode("\n", \array_column($reported, 'message'));
        $this->assertStringContainsString("'name'", $messages);
        $this->assertStringContainsString("'age'", $messages);
        $this->assertStringContainsString("'profile'", $messages);
    }

    #[Test]
    public function a_data_value_that_does_not_satisfy_the_declared_type_is_reported(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'WrongType.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
        $this->assertStringContainsString('$name', $reported[0]['message']);
        $this->assertStringContainsString('string', $reported[0]['message']);
    }

    #[Test]
    public function a_with_chain_contributes_its_key_to_the_same_contract_check(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'WithChain.php');

        // The inner view('greeting') supplies no data at all; only the outermost call sees the
        // whole chain, so the single issue must be the type mismatch, never a missing 'name'.
        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    #[Test]
    public function a_response_view_call_is_checked_like_the_helper(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'ResponseView.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    #[Test]
    public function a_mailable_view_chain_resolves_through_its_userland_subclass(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'MailableChain.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    /**
     * A `@props` entry with a literal default is optional and one without is not. Both halves are
     * asserted together: a template whose props failed to parse would be silent for BOTH, and the
     * optional case alone could not tell that apart from the gate working.
     */
    #[Test]
    public function only_the_props_entry_without_a_default_is_required(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $required = $this->forFile($issues, 'MissingRequiredProp.php');
        $this->assertCount(1, $required, \var_export($issues, true));
        $this->assertSame(self::MISSING, $required[0]['type']);
        $this->assertStringContainsString("'subtitle'", $required[0]['message']);

        $this->assertSame([], $this->forFile($issues, 'OptionalProp.php'));
    }

    /**
     * An open key set silences the missing-variable check without silencing the type check: the
     * keys that ARE known are still known.
     */
    #[Test]
    public function merge_data_leaves_the_key_set_open_but_keeps_the_type_check(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'UnsealedData.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    /**
     * A template that declares nothing still claims its view name. Two view roots both hold
     * `dup.blade.php`; the first root's file declares nothing and is the one Laravel renders, so the
     * second root's `@var $x` must never reach this call site.
     */
    #[Test]
    public function a_declaration_less_template_shadows_a_declaring_one_in_a_later_view_root(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'ShadowedTemplate.php'), \var_export($issues, true));
    }

    /** Each of these pins a different decline gate, with everything else about the call held equal. */
    #[Test]
    public function every_decline_gate_stays_silent(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        // Complete and well-typed data.
        $this->assertSame([], $this->forFile($issues, 'Correct.php'));
        // Same shape as MissingVariable.php, but the name is not a literal.
        $this->assertSame([], $this->forFile($issues, 'DynamicName.php'));
        // Same shape as MissingVariable.php, but the template declares nothing.
        $this->assertSame([], $this->forFile($issues, 'NoContract.php'));
    }

    #[Test]
    public function the_check_is_off_unless_the_config_flag_opts_in(): void
    {
        $this->assertSame([], $this->contractIssues('psalm-validation-off.xml'));
    }
}
