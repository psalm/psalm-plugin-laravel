<?php

declare(strict_types=1);

namespace PsalmLaravelPlugin\Ci\TodoBy;

/**
 * Reads an issue's or pull request's state from the GitHub REST API (`/issues/{n}` serves both). Throws on
 * anything but a clean 200, so a rate limit, outage or deleted issue can never read as "resolved".
 */
final readonly class GitHubIssueStateResolver
{
    public function __construct(private ?string $token) {}

    public function __invoke(TodoByMarker $marker): IssueState
    {
        $headers = ['Accept: application/vnd.github+json', 'User-Agent: psalm-plugin-laravel-todo-by', 'X-GitHub-Api-Version: 2022-11-28'];
        if ($this->token !== null && $this->token !== '') {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        $url = "https://api.github.com/repos/{$marker->owner}/{$marker->repo}/issues/{$marker->number}";
        // fopen() rather than file_get_contents(): the response headers come from the stream metadata, since
        // the magic $http_response_header variable is deprecated in PHP 8.5.
        $stream = @\fopen($url, 'r', false, \stream_context_create(['http' => [
            'method' => 'GET',
            'header' => \implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 20,
        ]]));
        $body = $stream === false ? false : \stream_get_contents($stream);
        $statusLine = $stream === false ? null : (\stream_get_meta_data($stream)['wrapper_data'][0] ?? null);
        $status = \is_string($statusLine) && \preg_match('~ (\d{3})\b~', $statusLine, $m) === 1 ? (int) $m[1] : 0;

        if ($body === false || $status !== 200) {
            $detail = \is_string($body) && \is_array($decoded = \json_decode($body, true)) && \is_string($decoded['message'] ?? null) ? ": {$decoded['message']}" : '';

            throw new \RuntimeException($status === 0 ? 'no response from api.github.com' : "HTTP {$status}{$detail}");
        }

        $data = \json_decode($body, true);
        if (!\is_array($data) || !\in_array($data['state'] ?? null, ['open', 'closed'], true)) {
            throw new \RuntimeException('unexpected API response');
        }

        return new IssueState($data['state'], \is_string($data['closed_at'] ?? null) ? $data['closed_at'] : null);
    }
}
