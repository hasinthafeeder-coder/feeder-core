<?php

namespace Feeder\Core\Authorization\Services;

use Feeder\Core\Authorization\Menu\AdminMenu;
use Feeder\Core\Authorization\Menu\ResellerMenu;
use Feeder\Core\Authorization\Menu\SupplierMenu;
use Feeder\Core\Authorization\Menu\Menu;
use Feeder\Core\Authorization\Menu\MenuItem;
use Feeder\Core\Authorization\Menu\MenuSection;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\User;

class MenuService
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    public function getForUser(User $user): Menu
    {
        $menu = match ($this->resolvePortalCode($user)) {
            PortalCode::ADMIN->value => AdminMenu::build(),
            PortalCode::RESELLER->value => ResellerMenu::build(),
            PortalCode::SUPPLIER->value => SupplierMenu::build(),

            default => new Menu(),
        };

        return $this->filterMenu($menu, $user);
    }

    /**
     * Menus are portal-scoped. user_type is OWNER/EMPLOYEE/SUPER_ADMIN —
     * resolve portal from role, then company, with SUPER_ADMIN mapped to ADMIN.
     */
    protected function resolvePortalCode(User $user): ?string
    {
        if ($user->user_type === UserType::SUPER_ADMIN->value) {
            return PortalCode::ADMIN->value;
        }

        $portalCode = $user->role?->portal?->code;

        if ($portalCode) {
            return $portalCode;
        }

        return $user->company?->portal?->code;
    }

    public function getAdminMenu(User $user): Menu
    {
        return $this->filterMenu(
            AdminMenu::build(),
            $user
        );
    }

    public function getResellerMenu(User $user): Menu
    {
        return $this->filterMenu(
            ResellerMenu::build(),
            $user
        );
    }

    public function getSupplierMenu(User $user): Menu
    {
        return $this->filterMenu(
            SupplierMenu::build(),
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
