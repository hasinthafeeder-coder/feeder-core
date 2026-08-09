<?php

namespace Feeder\Core\Authorization\Menu;

class MenuSection
{
    protected string $title;
    protected array $items = [];

    public static function make(string $title): static
    {
        $section = new static();
        $section->title = $title;
        return $section;
    }

    public function items(array $items): static
    {
        $this->items = $items;
        return $this;
    }

    public function addItem(MenuItem $item): static
    {
        $this->items[] = $item;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function hasItems(): bool
    {
        return count($this->items) > 0;
    }
}
