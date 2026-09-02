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
}
