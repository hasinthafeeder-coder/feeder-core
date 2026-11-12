<?php

namespace Tests\Unit\Support;

use Feeder\Core\Support\YouTubeUrl;
use PHPUnit\Framework\TestCase;

class YouTubeUrlTest extends TestCase
{
    public function test_it_accepts_supported_youtube_url_formats(): void
    {
        $cases = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'http://youtube.com/watch?v=dQw4w9WgXcQ&t=30s',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
        ];

        foreach ($cases as $url) {
            $this->assertTrue(YouTubeUrl::isValid($url), $url);
            $this->assertSame('dQw4w9WgXcQ', YouTubeUrl::extractVideoId($url));
            $this->assertSame(
                'https://www.youtube.com/embed/dQw4w9WgXcQ',
                YouTubeUrl::embedUrl($url)
            );
        }
    }

    public function test_it_rejects_invalid_or_non_youtube_urls(): void
    {
        $cases = [
            null,
            '',
            'not-a-url',
            'https://example.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=short',
            'https://www.youtube.com/playlist?list=PLtest',
            'javascript:alert(1)',
        ];

        foreach ($cases as $url) {
            $this->assertFalse(YouTubeUrl::isValid($url), (string) $url);
            $this->assertNull(YouTubeUrl::extractVideoId($url));
            $this->assertNull(YouTubeUrl::embedUrl($url));
        }
    }

    public function test_it_normalises_to_canonical_watch_url(): void
    {
        $this->assertSame(
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            YouTubeUrl::normalise('https://youtu.be/dQw4w9WgXcQ')
        );
    }
}
