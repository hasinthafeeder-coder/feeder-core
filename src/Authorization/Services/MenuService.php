<?php

namespace Feeder\Core\Authorization\Services;

use Feeder\Core\Authorization\Menu\AdminMenu;
use Feeder\Core\Authorization\Menu\Menu;
use Feeder\Core\Authorization\Menu\MenuItem;
use Feeder\Core\Authorization\Menu\MenuSection;
use Feeder\Core\Models\User;

class MenuService
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    public function getForUser(User $user): Menu
    {
        return $this->filterMenu(
            AdminMenu::build(),
            $user
        );
    }

    protected function filterMenu(Menu $menu, User $user): Menu
    {
        $filteredMenu = new Menu();

        foreach ($menu->getSections() as $section) {
            $filteredSection = $this->filterSection($section, $user);

            if ($filteredSection !== null) {
                $filteredMenu->addSection($filteredSection);
            }
        }

        return $filteredMenu;
    }

    protected function filterSection(MenuSection $section, User $user): ?MenuSection
    {
        $filteredItems = [];

        foreach ($section->getItems() as $item) {
            $filteredItem = $this->filterItem($item, $user);

            if ($filteredItem !== null) {
                $filteredItems[] = $filteredItem;
            }
        }

        if (empty($filteredItems)) {
            return null;
        }

        return MenuSection::make($section->getTitle())
            ->items($filteredItems);
    }

    protected function filterItem(
        MenuItem $item,
        User $user
    ): ?MenuItem {
        if (!$item->isVisible($user)) {
            return null;
        }

        $filteredChildren = [];

        foreach ($item->getChildren() as $child) {
            $filteredChild = $this->filterItem($child, $user);

            if ($filteredChild !== null) {
                $filteredChildren[] = $filteredChild;
            }
        }

        $hasPermission = true;

        if ($item->getPermission()) {
            $hasPermission = $this->permissionService->hasPermission(
                $user,
                $item->getPermission()
            );
        }

        if (!$hasPermission && empty($filteredChildren)) {
            return null;
        }

        return $item->copy()->children($filteredChildren);
    }
}
