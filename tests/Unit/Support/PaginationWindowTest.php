<?php

namespace Tests\Unit\Support;

use Feeder\Core\Support\PaginationWindow;
use PHPUnit\Framework\TestCase;

class PaginationWindowTest extends TestCase
{
    public function test_returns_all_pages_when_total_is_small(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], PaginationWindow::pages(2, 5));
    }

    public function test_builds_compact_window_with_ellipsis_for_large_page_counts(): void
    {
        $this->assertSame(
            [1, '...', 4, 5, 6, '...', 20],
            PaginationWindow::pages(5, 20)
        );
    }

    public function test_shows_start_window_without_leading_ellipsis(): void
    {
        $this->assertSame(
            [1, 2, 3, 4, '...', 20],
            PaginationWindow::pages(2, 20)
        );
    }

    public function test_shows_end_window_without_trailing_ellipsis(): void
    {
        $this->assertSame(
            [1, '...', 17, 18, 19, 20],
            PaginationWindow::pages(19, 20)
        );
    }
}
