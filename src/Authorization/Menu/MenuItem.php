<?php

namespace Feeder\Core\Authorization\Menu;

use Closure;

class MenuItem
{
    protected string $title;
    protected ?string $icon = null;
    protected ?string $route = null;
    protected ?string $permission = null;
    protected array $children = [];
    protected ?Closure $visibleCallback = null;
    protected ?Closure $badgeCallback = null;

    public static function make(string $title): static
    {
        $item = new static();
        $item->title = $title;
        return $item;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }

    public function route(string $route): static
    {
        $this->route = $route;
        return $this;
    }

    public function permission(?string $permission): static
    {
        $this->permission = $permission;
        return $this;
    }

    public function children(array $children): static
    {
        $this->children = $children;
        return $this;
    }

    public function visible(Closure $callback): static
    {
        $this->visibleCallback = $callback;
        return $this;
    }

    public function badge(Closure $callback): static
    {
        $this->badgeCallback = $callback;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function getPermission(): ?string
    {
        return $this->permission;
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function isVisible($user): bool
    {
        if (!$this->visibleCallback) {
            return true;
        }

        return (bool)($this->visibleCallback)($user);
    }

    public function getBadge($user): mixed
    {
        if (!$this->badgeCallback) {
            return null;
        }

        return ($this->badgeCallback)($user);
    }

    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }

    public function getVisibleCallback(): ?Closure
    {
        return $this->visibleCallback;
    }

    public function getBadgeCallback(): ?Closure
    {
        return $this->badgeCallback;
    }

    public function copy(): static
    {
        $item  = static::make($this->title);

        $item->icon = $this->icon;
        $item->route = $this->route;
        $item->permission = $this->permission;
        $item->visibleCallback = $this->visibleCallback;
        $item->badgeCallback = $this->badgeCallback;
        $item->children = $this->children;

        return $item;
    }
}
