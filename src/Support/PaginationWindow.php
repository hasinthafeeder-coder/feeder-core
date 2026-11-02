<?php

namespace Feeder\Core\Support;

class PaginationWindow
{
    /**
     * Build a compact page-number window with ellipsis markers.
     *
     * @return list<int|string>
     */
    public static function pages(int $currentPage, int $lastPage, int $onEachSide = 2): array
    {
        if ($lastPage <= 1) {
            return $lastPage === 1 ? [1] : [];
        }

        $window = 1 + ($onEachSide * 2);

        if ($lastPage <= $window + 2) {
            return range(1, $lastPage);
        }

        $pages = [1];

        $rangeStart = max(2, $currentPage - $onEachSide);
        $rangeEnd = min($lastPage - 1, $currentPage + $onEachSide);

        if ($rangeStart > 2) {
            $pages[] = '...';
        }

        for ($page = $rangeStart; $page <= $rangeEnd; $page++) {
            $pages[] = $page;
        }

        if ($rangeEnd < $lastPage - 1) {
            $pages[] = '...';
        }

        $pages[] = $lastPage;

        return $pages;
    }

    /**
     * Page numbers for a long list: both ends, the current page, and ellipsis for the gaps.
     *
     * Page 1 of 39 is [1, 2, '...', 38, 39].
     *
     * @return list<int|string>
     */
    public static function compactPages(int $currentPage, int $lastPage, int $edge = 2): array
    {
        if ($lastPage < 1) {
            return [];
        }

        $currentPage = max(1, min($currentPage, $lastPage));
        $edge = max(1, $edge);

        $included = [];
        for ($page = 1; $page <= $lastPage; $page++) {
            $nearStart = $page <= $edge;
            $nearEnd = $page > $lastPage - $edge;
            $nearCurrent = abs($page - $currentPage) <= 1;
            if ($nearStart || $nearEnd || $nearCurrent) {
                $included[] = $page;
            }
        }

        $pages = [];
        $previous = null;
        foreach ($included as $page) {
            if ($previous !== null && $page > $previous + 1) {
                $pages[] = '...';
            }
            $pages[] = $page;
            $previous = $page;
        }

        return $pages;
    }
}
