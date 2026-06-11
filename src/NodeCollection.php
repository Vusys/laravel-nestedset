<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Eloquent collection with in-memory tree-building utilities.
 *
 * All three methods operate on the already-fetched models — they issue no
 * additional queries. Use them when you already have a flat result and want
 * to navigate it as a tree without round-tripping the database.
 *
 * @template TKey of array-key
 * @template TModel of Model&HasNestedSet
 *
 * @extends EloquentCollection<TKey, TModel>
 *
 * @phpstan-consistent-constructor
 */
class NodeCollection extends EloquentCollection
{
    /**
     * Populate the `parent` and `children` relations on every node in this
     * collection by inspecting parent_id values. Overwrites any existing
     * values on those relation slots.
     */
    public function linkNodes(): static
    {
        if ($this->isEmpty()) {
            return $this;
        }

        /** @var array<int|string, list<TModel>> $byParentId */
        $byParentId = [];

        /** @var array<int|string, TModel> $byKey */
        $byKey = [];

        foreach ($this->items as $node) {
            $byKey[$this->keyOf($node)] = $node;

            $parentId = $node->getParentId();
            if ($parentId !== null) {
                $byParentId[$parentId][] = $node;
            }
        }

        foreach ($this->items as $node) {
            $parentId = $node->getParentId();

            if ($parentId === null) {
                // A true root has no parent — record that explicitly.
                $node->setRelation('parent', null);
            } elseif (isset($byKey[$parentId])) {
                $node->setRelation('parent', $byKey[$parentId]);
            }
            // else: the parent exists but wasn't fetched into this
            // collection (a partial / filtered result). Leave the
            // `parent` relation UNLOADED so lazy access still queries the
            // database — marking it loaded-null would silently hide a
            // parent that really exists.

            $node->setRelation(
                'children',
                $this->newSibling($byParentId[$this->keyOf($node)] ?? []),
            );
        }

        return $this;
    }

    /**
     * Returns the top-level nodes of the (sub)tree contained in this
     * collection, each with its `children` relation populated recursively
     * via {@see linkNodes()}.
     *
     * With no explicit `$root`, every node whose parent is NOT present in
     * the collection becomes a top-level node — so a partial or filtered
     * fetch returns a *forest* of all maximal subtrees it contains and no
     * node is ever silently dropped. (A node whose parent was filtered
     * out simply surfaces as its own root.)
     *
     * Passing `$root` narrows the result to that node's direct children
     * present in the collection — the subtree rooted at `$root`.
     */
    public function toTree(?HasNestedSet $root = null): static
    {
        if ($this->isEmpty()) {
            return $this->newSibling();
        }

        $this->linkNodes();

        $present = $this->presentKeySet();
        $tops = [];
        foreach ($this->items as $node) {
            if ($this->isTopLevel($node, $root, $present)) {
                $tops[] = $node;
            }
        }

        return $this->newSibling($tops);
    }

    /**
     * Returns the (sub)tree in depth-first order, preserving the same
     * ordering as a `defaultOrder()` query would produce. Follows the
     * same top-level rule as {@see toTree()} — a forest of every maximal
     * subtree present, or the subtree rooted at `$root` when given.
     */
    public function toFlatTree(?HasNestedSet $root = null): static
    {
        if ($this->isEmpty()) {
            return $this->newSibling();
        }

        /** @var array<string, list<TModel>> $byParentId */
        $byParentId = [];
        foreach ($this->items as $node) {
            $parentId = $node->getParentId();
            if ($parentId !== null) {
                $byParentId[(string) $parentId][] = $node;
            }
        }

        $present = $this->presentKeySet();
        $result = $this->newSibling();
        foreach ($this->items as $node) {
            if ($this->isTopLevel($node, $root, $present)) {
                $result->push($node);
                $this->flattenChildren($result, $byParentId, $node);
            }
        }

        return $result;
    }

    /**
     * A node is a top level of the result when an explicit `$root` is its
     * parent, or — with no root — when its parent is null or absent from
     * the collection (the forest rule).
     *
     * @param  array<string, true>  $present
     */
    private function isTopLevel(Model&HasNestedSet $node, ?HasNestedSet $root, array $present): bool
    {
        $parentId = $node->getParentId();

        if ($root instanceof Model) {
            return $parentId !== null && (string) $parentId === (string) $this->keyOf($root);
        }

        return $parentId === null || ! isset($present[(string) $parentId]);
    }

    /**
     * Set of primary keys present in this collection, string-keyed so
     * int and numeric-string ids index identically.
     *
     * @return array<string, true>
     */
    private function presentKeySet(): array
    {
        /** @var array<string, true> $present */
        $present = [];
        foreach ($this->items as $node) {
            $present[(string) $this->keyOf($node)] = true;
        }

        return $present;
    }

    /**
     * @param  static  $result
     * @param  array<string, list<TModel>>  $byParentId
     */
    private function flattenChildren(self $result, array $byParentId, Model $node): void
    {
        foreach ($byParentId[(string) $this->keyOf($node)] ?? [] as $child) {
            $result->push($child);
            $this->flattenChildren($result, $byParentId, $child);
        }
    }

    /**
     * Narrows the mixed return of Model::getKey() to an int|string. Nested-set
     * models all have scalar primary keys; this method exists so callers don't
     * have to repeat the cast.
     */
    private function keyOf(Model $node): int|string
    {
        $key = $node->getKey();

        if (is_int($key) || is_string($key)) {
            return $key;
        }

        throw new \LogicException(
            'NestedSet models must have a scalar primary key; got '.get_debug_type($key),
        );
    }

    /**
     * @param  array<int, TModel>  $items
     */
    private function newSibling(array $items = []): static
    {
        return new static($items);
    }
}
