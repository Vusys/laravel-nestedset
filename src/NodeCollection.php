<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\NestedSetLogicException;

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

            $node->setRelation(
                'parent',
                $parentId !== null && isset($byKey[$parentId]) ? $byKey[$parentId] : null,
            );

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
     * When $root is null, the top-level is inferred as the parent_id of the
     * node with the smallest lft — i.e. the natural root of whatever subset
     * was fetched.
     */
    public function toTree(?HasNestedSet $root = null): static
    {
        if ($this->isEmpty()) {
            return $this->newSibling();
        }

        $this->linkNodes();

        $rootKey = $this->resolveRootKey($root);

        $tops = [];

        foreach ($this->items as $node) {
            if ($node->getParentId() === $rootKey) {
                $tops[] = $node;
            }
        }

        return $this->newSibling($tops);
    }

    /**
     * Returns the (sub)tree in depth-first order, preserving the same
     * ordering as a `defaultOrder()` query would produce.
     */
    public function toFlatTree(?HasNestedSet $root = null): static
    {
        if ($this->isEmpty()) {
            return $this->newSibling();
        }

        /** @var array<int|string, list<TModel>> $byParentId */
        $byParentId = [];

        foreach ($this->items as $node) {
            $parentId = $node->getParentId();
            if ($parentId !== null) {
                $byParentId[$parentId][] = $node;
            }
        }

        $rootKey = $this->resolveRootKey($root);
        $result = $this->newSibling();

        $this->flattenInto($result, $byParentId, $rootKey);

        return $result;
    }

    /**
     * @param  static  $result
     * @param  array<int|string, list<TModel>>  $byParentId
     */
    private function flattenInto(self $result, array $byParentId, int|string|null $parentKey): void
    {
        if ($parentKey === null) {
            // Roots have parent_id = null; iterate items in original order to
            // preserve depth-first traversal for the root level.
            foreach ($this->items as $node) {
                if ($node->getParentId() === null) {
                    $result->push($node);
                    $this->flattenInto($result, $byParentId, $this->keyOf($node));
                }
            }

            return;
        }

        foreach ($byParentId[$parentKey] ?? [] as $node) {
            $result->push($node);
            $this->flattenInto($result, $byParentId, $this->keyOf($node));
        }
    }

    private function resolveRootKey(?HasNestedSet $root): int|string|null
    {
        if ($root instanceof Model) {
            return $this->keyOf($root);
        }

        // Infer: the parent_id of the lowest-lft node is the implicit root.
        $leastLft = null;
        $rootKey = null;

        foreach ($this->items as $node) {
            if ($leastLft === null || $node->getLft() < $leastLft) {
                $leastLft = $node->getLft();
                $rootKey = $node->getParentId();
            }
        }

        return $rootKey;
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

        throw new NestedSetLogicException(
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
