<?php

namespace Feeder\Core\Authorization\Menu;

class Menu
{
    protected array $sections = [];

    public function addSection(MenuSection $section): static
    {
        $this->sections[] = $section;
        return $this;
    }

    public function sections(array $sections): static
    {
        $this->sections = $sections;
        return $this;
    }

    public function getSections(): array
    {
        return $this->sections;
    }

    public function hasSections(): bool
    {
        return count($this->sections) > 0;
    }
}
