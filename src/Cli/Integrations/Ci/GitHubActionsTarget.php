<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Integrations\Ci;

/**
 * CI adapter for GitHub Actions.
 *
 * Copies the bundled `resources/ci/github-actions/psalm.yml` template verbatim
 * into `.github/workflows/psalm.yml` in the target project. The template is
 * shipped as a separate file (not an inlined heredoc) so contributors can edit
 * it with YAML tooling and so future adapters can reuse the same "copy one
 * file" shape without duplicating string literals.
 */
final class GitHubActionsTarget implements CiTargetInterface
{
    /**
     * Path inside the plugin package to the bundled workflow template,
     * relative to the plugin root.
     */
    private const TEMPLATE_RELATIVE_PATH = 'resources/ci/github-actions/psalm.yml';

    /**
     * Destination inside the user's project, relative to $projectRoot.
     * Matches the single-workflow-file shape described in issue #811.
     */
    private const WORKFLOW_RELATIVE_PATH = '.github/workflows/psalm.yml';

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        /**
         * Override the bundled template path. Tests use this to point at a
         * fixture; production callers let the default resolve the plugin's
         * own shipped file via dirname(__DIR__, 4).
         */
        private readonly ?string $templatePathOverride = null,
    ) {}

    /**
     * @psalm-pure
     */
    #[\Override]
    public function id(): string
    {
        return 'github';
    }

    /**
     * @psalm-pure
     */
    #[\Override]
    public function displayName(): string
    {
        return 'GitHub Actions';
    }

    /**
     * @psalm-impure
     */
    #[\Override]
    public function detect(string $projectRoot): bool
    {
        return \is_dir($this->joinPath($projectRoot, '.github'));
    }

    /**
     * @psalm-impure
     */
    #[\Override]
    public function plan(string $projectRoot): CiPlan
    {
        $templatePath = $this->resolveTemplatePath();

        // file_get_contents returns false on failure instead of throwing.
        // An empty string is technically a successful read, but an empty
        // workflow file would break CI on the next push with a confusing
        // "invalid workflow" error; treat it as failure too. Both cases point
        // at a broken plugin install, which is what the caller needs to know.
        $contents = @\file_get_contents($templatePath);
        if ($contents === false || $contents === '') {
            $error = \error_get_last();
            $reason = isset($error['message']) ? ': ' . $error['message'] : '';
            throw new \RuntimeException(\sprintf(
                'Bundled GitHub Actions template at %s is unreadable or empty%s',
                $templatePath,
                $reason,
            ));
        }

        $destination = $this->joinPath($projectRoot, self::WORKFLOW_RELATIVE_PATH);

        return new CiPlan($destination, $contents, \file_exists($destination));
    }

    /**
     * @psalm-mutation-free
     */
    private function resolveTemplatePath(): string
    {
        if ($this->templatePathOverride !== null) {
            return $this->templatePathOverride;
        }

        // This file lives at src/Cli/Integrations/Ci/GitHubActionsTarget.php;
        // four dirname() steps reach the plugin root regardless of whether the
        // plugin is installed via Composer (vendor/psalm/plugin-laravel/) or
        // symlinked from a local clone.
        return \dirname(__DIR__, 4) . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, self::TEMPLATE_RELATIVE_PATH);
    }

    /**
     * @param non-empty-string $relative
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private function joinPath(string $root, string $relative): string
    {
        return \rtrim($root, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, $relative);
    }
}
