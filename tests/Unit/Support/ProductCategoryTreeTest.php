<?php

namespace Tests\Unit\Support;

use Feeder\Core\Models\ProductCategory;
use Feeder\Core\Support\ProductCategoryTree;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ProductCategoryTreeTest extends TestCase
{
    public function test_build_tree_nests_children_under_parents(): void
    {
        $root = $this->makeCategory('Electronics');
        $child = $this->makeCategory('Audio', $root->id);
        $grandchild = $this->makeCategory('Earphones', $child->id);

        $roots = ProductCategoryTree::build(new Collection([$root, $child, $grandchild]));

        $this->assertCount(1, $roots);
        $this->assertSame($root->id, $roots->first()->id);
        $this->assertCount(1, $roots->first()->children);
        $this->assertSame($child->id, $roots->first()->children->first()->id);
        $this->assertCount(1, $roots->first()->children->first()->children);
        $this->assertSame($grandchild->id, $roots->first()->children->first()->children->first()->id);
    }

    public function test_expand_with_ancestors_includes_parent_chain(): void
    {
        $root = $this->makeCategory('Electronics');
        $child = $this->makeCategory('Audio', $root->id);
        $grandchild = $this->makeCategory('Earphones', $child->id);

        $categories = new Collection([$root, $child, $grandchild]);

        $expanded = ProductCategoryTree::expandWithAncestors($categories, [$grandchild->id]);

        $this->assertEqualsCanonicalizing(
            [$root->id, $child->id, $grandchild->id],
            $expanded
        );
    }

    public function test_expand_with_descendants_includes_nested_categories(): void
    {
        $root = $this->makeCategory('Electronics');
        $child = $this->makeCategory('Audio', $root->id);
        $grandchild = $this->makeCategory('Earphones', $child->id);

        $categories = new Collection([$root, $child, $grandchild]);

        $expanded = ProductCategoryTree::expandWithDescendants($categories, [$root->id]);

        $this->assertEqualsCanonicalizing(
            [$root->id, $child->id, $grandchild->id],
            $expanded
        );
    }

    private function makeCategory(string $name, ?string $parentId = null): ProductCategory
    {
        $category = new ProductCategory([
            'id' => strtolower(str_replace(' ', '-', $name)).'-'.random_int(100, 999),
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'sort_order' => 1,
            'is_active' => true,
        ]);

        return $category;
    }
}
