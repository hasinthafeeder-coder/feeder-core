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

    public function test_compact_pages_show_both_ends_on_the_first_page(): void
    {
        $this->assertSame([1, 2, '...', 38, 39], PaginationWindow::compactPages(1, 39));
    }

    public function test_compact_pages_keep_the_current_page_between_the_ends(): void
    {
        $this->assertSame(
            [1, 2, '...', 19, 20, 21, '...', 38, 39],
            PaginationWindow::compactPages(20, 39)
        );
    }

    public function test_compact_pages_list_every_page_when_the_list_is_short(): void
    {
        $this->assertSame([1, 2, 3, 4], PaginationWindow::compactPages(2, 4));
    }
}
