<?php

namespace Feeder\Core\Support;

/**
 * Validate and normalise supported public YouTube watch / short / embed URLs.
 */
final class YouTubeUrl
{
    private const VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    public static function isValid(?string $url): bool
    {
        return self::extractVideoId($url) !== null;
    }

    public static function extractVideoId(?string $url): ?string
    {
        $candidate = trim((string) $url);

        if ($candidate === '') {
            return null;
        }

        if (! filter_var($candidate, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = preg_replace('/^www\./', '', $host) ?? $host;

        $videoId = null;

        if ($host === 'youtu.be') {
            $videoId = trim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            if (str_starts_with($path, '/watch')) {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $videoId = isset($query['v']) ? (string) $query['v'] : null;
            } elseif (preg_match('#^/(embed|shorts|live|v)/([^/?&]+)#', $path, $matches) === 1) {
                $videoId = $matches[2];
            }
        }

        if ($videoId === null || $videoId === '') {
            return null;
        }

        // Strip accidental query fragments from path captures.
        $videoId = explode('?', $videoId, 2)[0];
        $videoId = explode('&', $videoId, 2)[0];

        if (preg_match(self::VIDEO_ID_PATTERN, $videoId) !== 1) {
            return null;
        }

        return $videoId;
    }

    public static function normalise(?string $url): ?string
    {
        $videoId = self::extractVideoId($url);

        if ($videoId === null) {
            return null;
        }

        return 'https://www.youtube.com/watch?v='.$videoId;
    }

    public static function embedUrl(?string $url): ?string
    {
        $videoId = self::extractVideoId($url);

        if ($videoId === null) {
            return null;
        }

        return 'https://www.youtube.com/embed/'.$videoId;
    }
}
