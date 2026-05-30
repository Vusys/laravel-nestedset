<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Testing;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use LogicException;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Adds tree-shaped fixture builders to a Laravel `Factory`. The trait
 * targets factories whose model uses `NodeTrait` — every produced row
 * goes through `bulkInsertTree`, so even depth-3-branching-5 (156
 * nodes) costs three statements instead of one hundred fifty-six.
 *
 * Two entry points cover the common cases:
 *
 *   - {@see self::tree()}            uniform / array / closure branching
 *   - {@see self::treeFromShape()}   explicit nested-array shape
 *
 * Plus {@see self::previewTree()} for snapshot-testing the resolved
 * shape without a database round-trip.
 *
 * See `docs/testing/factories.md` for usage; see
 * `FACTORY_TREE_DESIGN.md` for the design rationale.
 *
 * @template TModel of Model&HasNestedSet
 *
 * @mixin Factory<TModel>
 */
trait BuildsNestedSetTrees
{
    protected ?TreeBuilderShape $treeShape = null;

    /**
     * Build a uniform-shaped tree.
     *
     * @param  int  $depth  Generations below the root. 0 = single root.
     * @param  int|list<int>|(Closure(int $parentDepth): int)  $branching
     * @param  ?string  $labelColumn  Column to write `Depth d Sibling i` to (default `'name'`); pass `null` to skip injection.
     * @param  null|(Closure(int $depth, int $siblingIndex, ?array<string, mixed> $parentAttrs): array<string, mixed>)  $per
     */
    public function tree(
        int $depth,
        int|array|Closure $branching,
        ?HasNestedSet $parent = null,
        ?string $labelColumn = 'name',
        ?Closure $per = null,
        bool $afterCreating = true,
    ): static {
        if ($depth < 0) {
            throw new InvalidArgumentException(sprintf('tree(): depth must be >= 0, got %d.', $depth));
        }

        if (is_int($branching)) {
            if ($branching < 1 && $depth > 0) {
                throw new InvalidArgumentException(
                    'tree(): branching must be >= 1 when depth > 0 (a non-leaf with zero children cannot exist; use depth: 0 for a single root).',
                );
            }
        } elseif (is_array($branching)) {
            if (count($branching) < $depth) {
                throw new InvalidArgumentException(sprintf(
                    'tree(): branching array length (%d) is less than depth (%d) — provide one entry per generation.',
                    count($branching),
                    $depth,
                ));
            }
            foreach ($branching as $level => $count) {
                if ($count < 1 && $level < $depth) {
                    throw new InvalidArgumentException(sprintf(
                        'tree(): branching[%d] is %d but depth %d still requires children.',
                        $level,
                        $count,
                        $depth,
                    ));
                }
            }
        }

        return $this->withTreeShape(new TreeBuilderShape(
            kind: TreeBuilderShape::KIND_UNIFORM,
            depth: $depth,
            branching: $branching,
            explicitShape: [],
            parent: $parent,
            labelColumn: $labelColumn,
            per: $per,
            afterCreating: $afterCreating,
        ));
    }

    /**
     * Build a tree (or forest) from an explicit nested-array shape. Each
     * entry is an associative array of model attributes plus an optional
     * `children` key (reserved — it always names structural children,
     * never a model column).
     *
     * @param  list<array<string, mixed>>  $shape
     */
    public function treeFromShape(
        array $shape,
        ?HasNestedSet $parent = null,
        bool $afterCreating = true,
    ): static {
        if ($shape === []) {
            throw new InvalidArgumentException(
                'treeFromShape(): shape must contain at least one top-level entry.',
            );
        }

        return $this->withTreeShape(new TreeBuilderShape(
            kind: TreeBuilderShape::KIND_EXPLICIT,
            depth: 0,
            branching: 0,
            explicitShape: $shape,
            parent: $parent,
            labelColumn: null,
            per: null,
            afterCreating: $afterCreating,
        ));
    }

    /**
     * Return the fully-resolved nested-array payload that would have been
     * handed to `bulkInsertTree`, without persisting anything. The output
     * is the same shape `treeFromShape` accepts as input, so a
     * `previewTree()` result can be replayed for deterministic re-runs.
     *
     * @return list<array<string, mixed>>
     */
    public function previewTree(): array
    {
        $shape = $this->treeShape;
        if ($shape === null) {
            throw new LogicException('previewTree(): call tree() or treeFromShape() first.');
        }

        $this->assertParentLive($shape->parent);
        $this->assertLabelColumnExists($shape->labelColumn);
        $this->assertParentScopeMatches($shape->parent);

        return $this->resolveShapeFor($shape);
    }

    /**
     * Override Laravel's `create()` to short-circuit into the bulk-insert
     * path when a tree shape has been queued. Falls through to the parent
     * implementation when no shape is set.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return Collection<int, TModel>|TModel
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if ($this->treeShape === null) {
            /** @var Collection<int, TModel>|TModel $result */
            $result = parent::create($attributes, $parent);

            return $result;
        }

        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        return $this->createTrees();
    }

    /**
     * Override Laravel's `make()` to reject calls when a tree shape has
     * been queued — building a placed tree without inserting is ambiguous
     * (no IDs for `parent_id`, no lft/rgt computed). Falls through to the
     * parent implementation when no shape is set.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return Collection<int, TModel>|TModel
     */
    public function make($attributes = [], ?Model $parent = null)
    {
        if ($this->treeShape !== null) {
            throw new LogicException(
                'The tree factory builder requires create() — make() cannot build a placed tree without inserting. '
                .'Use previewTree() for the resolved attribute payload without a DB round-trip.',
            );
        }

        /** @var Collection<int, TModel>|TModel $result */
        $result = parent::make($attributes, $parent);

        return $result;
    }

    /**
     * Reject `tree(...)->count(...)` since the order is undefined: should
     * count multiply the trees (treat tree as the unit) or the rows inside
     * the tree? The canonical Laravel idiom is `count(N)->tree(...)`, so we
     * enforce that ordering.
     */
    public function count(?int $count): static
    {
        if ($this->treeShape !== null) {
            throw new LogicException(
                'count() must come before tree()/treeFromShape() — tree-then-count is undefined; '
                .'use count(N)->tree(...) for N independent trees.',
            );
        }

        /** @var static $result */
        $result = parent::count($count);

        return $result;
    }

    /**
     * Preserve `$treeShape` across the cloning that Laravel's chainable
     * methods do internally (state, count, sequence, …).
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        /** @var static $instance */
        $instance = parent::newInstance($arguments);
        $instance->treeShape = $this->treeShape;

        return $instance;
    }

    /**
     * Clone the factory and stamp the given shape onto the clone. The
     * shape lives only on the new instance — chaining off the original
     * (e.g. `$factory->tree(...); $factory->create();`) won't pick it up,
     * matching Laravel's immutable factory contract.
     */
    private function withTreeShape(TreeBuilderShape $shape): static
    {
        $clone = clone $this;
        $clone->treeShape = $shape;

        return $clone;
    }

    /**
     * Resolves the queued shape into one or more trees and dispatches them
     * to `bulkInsertTree`. Honours `count()` for independent-tree builds.
     *
     * @return Collection<int, TModel>|TModel
     */
    private function createTrees()
    {
        $shape = $this->treeShape;

        if ($shape === null) {
            throw new LogicException('createTrees() called without a queued shape.');
        }

        $this->assertParentLive($shape->parent);
        $this->assertLabelColumnExists($shape->labelColumn);
        $this->assertParentScopeMatches($shape->parent);

        $iterations = $this->count ?? 1;

        /** @var Collection<int, TModel> $emptyCollection */
        $emptyCollection = $this->newModel()->newCollection();
        if ($iterations < 1) {
            return $emptyCollection;
        }

        /** @var list<TModel> $allRoots */
        $allRoots = [];
        /** @var list<TModel> $allInserted */
        $allInserted = [];

        for ($i = 0; $i < $iterations; $i++) {
            $resolved = $this->resolveShapeFor($shape);

            /** @var class-string<Model&HasNestedSet> $modelClass */
            $modelClass = $this->modelName();

            /** @var list<TModel> $inserted */
            $inserted = Model::unguarded(
                fn (): array => $this->dispatchBulkInsert($modelClass, $resolved, $shape->parent),
            );

            $rootCount = count($resolved);
            for ($r = 0; $r < $rootCount; $r++) {
                $allRoots[] = $inserted[$this->rootPlanIndexFor($resolved, $r)];
            }

            foreach ($inserted as $node) {
                $allInserted[] = $node;
            }
        }

        if ($shape->afterCreating) {
            $this->callAfterCreating(
                new SupportCollection($allInserted),
            );
        }

        $singleRoot = $this->count === null
            && $shape->kind === TreeBuilderShape::KIND_UNIFORM;
        $singleExplicitRoot = $this->count === null
            && $shape->kind === TreeBuilderShape::KIND_EXPLICIT
            && count($shape->explicitShape) === 1;

        if ($singleRoot || $singleExplicitRoot) {
            return $allRoots[0];
        }

        /** @var Collection<int, TModel> $collection */
        $collection = $this->newModel()->newCollection($allRoots);

        return $collection;
    }

    /**
     * Returns the plan index of the $n-th top-level entry in the
     * already-resolved shape. `bulkInsertTree` walks the input
     * depth-first and inserts in pre-order, so the i-th top-level
     * entry maps to a specific offset in the returned list (the sum of
     * subtree sizes of all preceding top-level entries).
     *
     * @param  list<array<string, mixed>>  $resolved
     */
    private function rootPlanIndexFor(array $resolved, int $n): int
    {
        $offset = 0;
        for ($i = 0; $i < $n; $i++) {
            $offset += $this->subtreeSize($resolved[$i]);
        }

        return $offset;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function subtreeSize(array $node): int
    {
        $size = 1;
        $children = $node[TreeBuilderShape::CHILDREN_KEY] ?? [];
        if (is_array($children)) {
            /** @var array<int, array<string, mixed>> $children */
            foreach ($children as $child) {
                $size += $this->subtreeSize($child);
            }
        }

        return $size;
    }

    /**
     * Walks the queued shape in DFS pre-order, calling
     * `definition()` + `state()` + per-row hooks per node, and returns
     * the nested-array payload bulkInsertTree expects.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveShapeFor(TreeBuilderShape $shape): array
    {
        /** @var array<string, array<string, mixed>> $resolvedByPath  Keyed by serialised path so children can read parent's resolved attrs. */
        $resolvedByPath = [];

        $skeleton = $shape->normalise();
        /** @var list<array{path: list<int>, parentPath: list<int>|null, depth: int, siblingIndex: int, attributes: array<string, mixed>, children: list<array<string, mixed>>}> $walk */
        $walk = iterator_to_array(TreeBuilderShape::walkDfs($skeleton), false);

        $modelInstance = $this->modelInstance();
        $reservedCols = $this->reservedColumnsFor($modelInstance);

        foreach ($walk as $node) {
            $raw = $this->resolveRowAttributes(
                explicitAttrs: $node['attributes'],
                depth: $node['depth'],
                siblingIndex: $node['siblingIndex'],
                parentPath: $node['parentPath'],
                resolvedByPath: $resolvedByPath,
                shape: $shape,
                reservedCols: $reservedCols,
            );

            $resolvedByPath[$this->pathKey($node['path'])] = $raw;
        }

        return $this->assembleResolved($skeleton, $resolvedByPath, []);
    }

    /**
     * @param  list<array<string, mixed>>  $skeleton
     * @param  array<string, array<string, mixed>>  $resolvedByPath
     * @param  list<int>  $parentPath
     * @return list<array<string, mixed>>
     */
    private function assembleResolved(array $skeleton, array $resolvedByPath, array $parentPath): array
    {
        $out = [];
        foreach ($skeleton as $index => $node) {
            $path = [...$parentPath, $index];
            $attrs = $resolvedByPath[$this->pathKey($path)];

            $children = $node[TreeBuilderShape::CHILDREN_KEY] ?? [];
            if (! is_array($children)) {
                $children = [];
            }
            /** @var list<array<string, mixed>> $children */
            $resolvedChildren = $this->assembleResolved($children, $resolvedByPath, $path);

            $attrs[TreeBuilderShape::CHILDREN_KEY] = $resolvedChildren;
            $out[] = $attrs;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $explicitAttrs
     * @param  list<int>|null  $parentPath
     * @param  array<string, array<string, mixed>>  $resolvedByPath
     * @param  list<string>  $reservedCols
     * @return array<string, mixed>
     */
    private function resolveRowAttributes(
        array $explicitAttrs,
        int $depth,
        int $siblingIndex,
        ?array $parentPath,
        array $resolvedByPath,
        TreeBuilderShape $shape,
        array $reservedCols,
    ): array {
        /** @var array<string, mixed> $raw */
        $raw = $this->getExpandedAttributes(null);

        if ($shape->labelColumn !== null && $shape->kind === TreeBuilderShape::KIND_UNIFORM) {
            $raw[$shape->labelColumn] = sprintf('Depth %d Sibling %d', $depth, $siblingIndex);
        }

        if ($explicitAttrs !== []) {
            $raw = array_merge($raw, $explicitAttrs);
        }

        if ($shape->per instanceof Closure) {
            $parentAttrs = null;
            if ($parentPath !== null) {
                $parentAttrs = $resolvedByPath[$this->pathKey($parentPath)] ?? null;
                if (is_array($parentAttrs)) {
                    unset($parentAttrs[TreeBuilderShape::CHILDREN_KEY]);
                }
            }

            $perResult = ($shape->per)($depth, $siblingIndex, $parentAttrs);

            if (! is_array($perResult)) {
                throw new InvalidArgumentException(sprintf(
                    'tree(per: ...): closure must return an associative array of attributes, got %s.',
                    get_debug_type($perResult),
                ));
            }
            /** @var array<string, mixed> $perResult */
            $raw = array_merge($raw, $perResult);
        }

        foreach ($reservedCols as $reserved) {
            unset($raw[$reserved]);
        }

        return $raw;
    }

    /**
     * @param  list<int>  $path
     */
    private function pathKey(array $path): string
    {
        return implode('.', $path);
    }

    /**
     * @return list<string>
     */
    private function reservedColumnsFor(Model&HasNestedSet $instance): array
    {
        return [
            $instance->getLftName(),
            $instance->getRgtName(),
            $instance->getDepthName(),
            $instance->getParentIdName(),
            $instance->getKeyName(),
        ];
    }

    private function modelInstance(): Model&HasNestedSet
    {
        /** @var class-string<Model&HasNestedSet> $modelClass */
        $modelClass = $this->modelName();

        return new $modelClass;
    }

    /**
     * Dispatches the trait-provided static `bulkInsertTree` method via
     * a callable. The interface intentionally doesn't declare
     * `bulkInsertTree` (it ships from the `HasBulkInsert` trait so test
     * stubs implementing the contract by hand stay light), so we route
     * through `call_user_func` rather than a direct static-method call —
     * which would force the interface to grow a static-method slot.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  list<array<string, mixed>>  $tree
     * @return list<Model&HasNestedSet>
     */
    private function dispatchBulkInsert(string $modelClass, array $tree, ?HasNestedSet $appendTo): array
    {
        $callable = [$modelClass, 'bulkInsertTree'];
        if (! is_callable($callable)) {
            throw new LogicException(sprintf(
                'tree(): %s must use HasBulkInsert (typically via NodeTrait) to expose static bulkInsertTree().',
                $modelClass,
            ));
        }

        /** @var list<Model&HasNestedSet> $result */
        $result = $callable($tree, $appendTo);

        return $result;
    }

    /**
     * Refuse to graft a subtree onto a trashed parent — the integrity
     * constraint that catches this in the DB layer surfaces as an
     * opaque "FOREIGN KEY constraint failed", which is hostile when the
     * fix is a one-liner (`->restore()` or `null`).
     */
    private function assertParentLive(?HasNestedSet $parent): void
    {
        if (! $parent instanceof HasNestedSet) {
            return;
        }

        if (! in_array(SoftDeletes::class, $this->traitsOf($parent::class), true)) {
            return;
        }

        if (method_exists($parent, 'trashed') && $parent->trashed()) {
            throw new InvalidArgumentException(
                'tree(): cannot graft onto a trashed parent — restore it (->restore()) or pass null to seed a fresh root.',
            );
        }
    }

    /**
     * @param  class-string  $class
     * @return list<class-string>
     */
    private function traitsOf(string $class): array
    {
        /** @var list<class-string> $traits */
        $traits = [];
        $current = $class;
        while ($current !== false) {
            $found = class_uses($current);
            if (is_array($found)) {
                /** @var array<class-string, class-string> $found */
                foreach ($found as $trait) {
                    $traits[] = $trait;
                }
            }
            $current = get_parent_class($current);
        }

        return array_values(array_unique($traits));
    }

    private function assertParentScopeMatches(?HasNestedSet $parent): void
    {
        if (! $parent instanceof HasNestedSet) {
            return;
        }

        /** @var class-string<Model&HasNestedSet> $modelClass */
        $modelClass = $this->modelName();
        $scopeColumns = NestedSetScopeResolver::columns($modelClass);

        if ($scopeColumns === []) {
            return;
        }

        if (! $parent instanceof Model) {
            return;
        }

        if ($parent::class !== $modelClass) {
            throw new ScopeViolationException(sprintf(
                'tree(): $parent must be an instance of %s, got %s — a cross-class anchor would read the wrong table.',
                $modelClass,
                $parent::class,
            ));
        }

        $factoryScope = [];
        /** @var array<string, mixed> $sample */
        $sample = $this->getExpandedAttributes(null);
        foreach ($scopeColumns as $col) {
            if (array_key_exists($col, $sample)) {
                $factoryScope[$col] = $sample[$col];
            }
        }

        if ($factoryScope === []) {
            return;
        }

        $parentScope = NestedSetScopeResolver::valuesFor($parent);

        foreach ($factoryScope as $col => $value) {
            $parentValue = $parentScope[$col] ?? null;
            if (! $this->scopeValuesEqual($value, $parentValue)) {
                throw new ScopeViolationException(sprintf(
                    'tree(): factory scope %s=%s differs from parent scope %s=%s; cross-scope grafts would corrupt the index sequence.',
                    $col,
                    $this->scopeFormat($value),
                    $col,
                    $this->scopeFormat($parentValue),
                ));
            }
        }
    }

    private function scopeValuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a == $b;
    }

    private function scopeFormat(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }

        return get_debug_type($v);
    }

    private function assertLabelColumnExists(?string $labelColumn): void
    {
        if ($labelColumn === null) {
            return;
        }

        $instance = $this->modelInstance();

        $connection = $instance->getConnection();
        $table = $instance->getTable();

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return;
        }

        $columns = $connection->getSchemaBuilder()->getColumnListing($table);
        if (! in_array($labelColumn, $columns, true)) {
            throw new InvalidArgumentException(sprintf(
                'tree(): labelColumn "%s" does not exist on %s (table %s). Actual columns: %s.',
                $labelColumn,
                $instance::class,
                $table,
                implode(', ', $columns),
            ));
        }
    }
}
