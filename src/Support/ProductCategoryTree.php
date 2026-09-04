<?php

namespace Feeder\Core\Support;

use Feeder\Core\Models\ProductCategory;
use Illuminate\Support\Collection;

class ProductCategoryTree
{
    /**
     * Build a parent/child tree from a flat category collection.
     *
     * @param  Collection<int, ProductCategory>  $categories
     * @return Collection<int, ProductCategory>
     */
    public static function build(Collection $categories): Collection
    {
        $categories->each(function (ProductCategory $category): void {
            $category->setRelation('children', collect());
        });

        $byId = $categories->keyBy('id');

        foreach ($categories as $category) {
            if (empty($category->parent_id) || ! $byId->has($category->parent_id)) {
                continue;
            }

            $parent = $byId->get($category->parent_id);
            $children = $parent->getRelation('children') ?? collect();
            $children->push($category);
            $parent->setRelation('children', $children);
        }

        return $categories
            ->filter(fn (ProductCategory $category) => empty($category->parent_id))
            ->values();
    }

    /**
     * Include every ancestor for the supplied category IDs.
     *
     * @param  Collection<int, ProductCategory>  $categories
     * @param  list<string>  $categoryIds
     * @return list<string>
     */
    public static function expandWithAncestors(Collection $categories, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $byId = $categories->keyBy('id');
        $expanded = [];

        foreach ($categoryIds as $categoryId) {
            $current = $byId->get($categoryId);

            while ($current !== null) {
                $expanded[$current->id] = $current->id;
                $current = $current->parent_id ? $byId->get($current->parent_id) : null;
            }
        }

        return array_values($expanded);
    }

    /**
     * Include every descendant for the supplied category IDs.
     *
     * @param  Collection<int, ProductCategory>  $categories
     * @param  list<string>  $categoryIds
     * @return list<string>
     */
    public static function expandWithDescendants(Collection $categories, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $childrenByParent = $categories
            ->filter(fn (ProductCategory $category) => ! empty($category->parent_id))
            ->groupBy('parent_id');

        $expanded = [];

        $walk = function (string $categoryId) use (&$walk, &$expanded, $childrenByParent): void {
            $expanded[$categoryId] = $categoryId;

            foreach ($childrenByParent->get($categoryId, collect()) as $child) {
                $walk($child->id);
            }
        };

        foreach ($categoryIds as $categoryId) {
            $walk($categoryId);
        }

        return array_values($expanded);
    }

    /**
     * Determine whether a category belongs in a tree branch that contains products.
     *
     * @param  list<string>  $relevantCategoryIds
     */
    public static function isRelevantBranch(ProductCategory $category, array $relevantCategoryIds): bool
    {
        if (in_array($category->id, $relevantCategoryIds, true)) {
            return true;
        }

        $children = $category->getRelation('children') ?? collect();

        foreach ($children as $child) {
            if (self::isRelevantBranch($child, $relevantCategoryIds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove branches that do not contain any of the relevant category IDs.
     *
     * @param  Collection<int, ProductCategory>  $roots
     * @param  list<string>  $relevantCategoryIds
     * @return Collection<int, ProductCategory>
     */
    public static function pruneIrrelevantBranches(Collection $roots, array $relevantCategoryIds): Collection
    {
        return $roots
            ->filter(fn (ProductCategory $category) => self::isRelevantBranch($category, $relevantCategoryIds))
            ->map(function (ProductCategory $category) use ($relevantCategoryIds): ProductCategory {
                $children = $category->getRelation('children') ?? collect();
                $category->setRelation(
                    'children',
                    self::pruneIrrelevantBranches($children, $relevantCategoryIds)->values()
                );

                return $category;
            })
            ->values();
    }
}
