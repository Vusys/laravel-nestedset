<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Testing;

use Closure;
use InvalidArgumentException;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Internal request descriptor produced by {@see BuildsNestedSetTrees::tree()}
 * and {@see BuildsNestedSetTrees::treeFromShape()}. Carries the unresolved
 * shape (no `definition()` evaluation has happened yet) plus the build-time
 * knobs the trait needs at create-time.
 *
 * Not part of the public API — owned by the trait that constructed it. Kept
 * as a separate object so `previewTree()` and the shape-normalisation tests
 * can share the same expansion logic without instantiating a factory.
 *
 * @internal
 */
final readonly class TreeBuilderShape
{
    public const string KIND_UNIFORM = 'uniform';

    public const string KIND_EXPLICIT = 'explicit';

    public const string CHILDREN_KEY = 'children';

    /**
     * @param  self::KIND_*  $kind
     * @param  list<array<string, mixed>>  $explicitShape  Only meaningful when $kind === KIND_EXPLICIT.
     * @param  int|list<int>|Closure(int $parentDepth): mixed  $branching  Only meaningful when $kind === KIND_UNIFORM.
     * @param  null|Closure(int $depth, int $siblingIndex, ?array<string, mixed> $parentAttrs): mixed  $per
     */
    public function __construct(
        public string $kind,
        public int $depth,
        public int|array|Closure $branching,
        public array $explicitShape,
        public ?HasNestedSet $parent,
        public ?string $labelColumn,
        public ?Closure $per,
        public bool $afterCreating,
    ) {}

    /**
     * Expands the uniform/closure/array-branching request into the same
     * nested-array form `treeFromShape` accepts as input. Each entry has
     * a `children` key (always present, may be empty) and a `__meta`
     * stash with the per-row context the resolver needs later
     * (`depth`, `siblingIndex`).
     *
     * The shape returned here is "skeletal" — only `children` and `__meta`
     * keys are populated. Real attributes get merged in at resolve-time
     * by walking this shape and calling the factory's `definition()` /
     * `state()` / `$per` chain per node.
     *
     * @return list<array<string, mixed>>
     */
    public function normalise(): array
    {
        return match ($this->kind) {
            self::KIND_UNIFORM => $this->normaliseUniform(),
            self::KIND_EXPLICIT => $this->normaliseExplicit($this->explicitShape, 0, 0),
        };
    }

    /**
     * Iterates the normalised shape depth-first. Yields one tuple per node
     * containing the path, parent path (or null for roots), depth, sibling
     * index, and the raw (still-unresolved) attribute slice.
     *
     * Used by the resolver to walk the skeleton in DFS pre-order and apply
     * `definition()` / state / per-row hooks in the same order
     * `bulkInsertTree` later writes them.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<int>|null  $parentPath
     * @return \Generator<int, array{
     *     path: list<int>,
     *     parentPath: list<int>|null,
     *     depth: int,
     *     siblingIndex: int,
     *     attributes: array<string, mixed>,
     *     children: list<array<string, mixed>>,
     * }>
     */
    public static function walkDfs(array $nodes, ?array $parentPath = null): \Generator
    {
        foreach ($nodes as $index => $node) {
            /** @var list<int> $path */
            $path = $parentPath === null ? [$index] : [...$parentPath, $index];

            $children = [];
            $attributes = $node;
            if (array_key_exists(self::CHILDREN_KEY, $attributes)) {
                $rawChildren = $attributes[self::CHILDREN_KEY];
                unset($attributes[self::CHILDREN_KEY]);
                if (! is_array($rawChildren)) {
                    throw new InvalidArgumentException(sprintf(
                        'treeFromShape: "%s" must be an array of further nodes, got %s.',
                        self::CHILDREN_KEY,
                        get_debug_type($rawChildren),
                    ));
                }
                /** @var list<array<string, mixed>> $children */
                $children = array_values($rawChildren);
            }

            $meta = $node['__meta'] ?? null;
            $depth = is_array($meta) && isset($meta['depth']) && is_int($meta['depth'])
                ? $meta['depth']
                : count($path) - 1;
            $siblingIndex = is_array($meta) && isset($meta['siblingIndex']) && is_int($meta['siblingIndex'])
                ? $meta['siblingIndex']
                : $index;

            unset($attributes['__meta']);

            yield [
                'path' => $path,
                'parentPath' => $parentPath,
                'depth' => $depth,
                'siblingIndex' => $siblingIndex,
                'attributes' => $attributes,
                'children' => $children,
            ];

            if ($children !== []) {
                yield from self::walkDfs($children, $path);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normaliseUniform(): array
    {
        if ($this->depth === 0) {
            return [[
                self::CHILDREN_KEY => [],
                '__meta' => ['depth' => 0, 'siblingIndex' => 0],
            ]];
        }

        return [$this->buildUniformNode(0, 0)];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUniformNode(int $depth, int $siblingIndex): array
    {
        $children = [];

        if ($depth < $this->depth) {
            $childCount = $this->branchingFor($depth);
            for ($i = 0; $i < $childCount; $i++) {
                $children[] = $this->buildUniformNode($depth + 1, $i);
            }
        }

        return [
            self::CHILDREN_KEY => $children,
            '__meta' => ['depth' => $depth, 'siblingIndex' => $siblingIndex],
        ];
    }

    private function branchingFor(int $parentDepth): int
    {
        if (is_int($this->branching)) {
            return $this->branching;
        }

        if (is_array($this->branching)) {
            return $this->branching[$parentDepth];
        }

        $value = ($this->branching)($parentDepth);

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf(
                'tree(): branching closure must return int, got %s.',
                get_debug_type($value),
            ));
        }

        if ($value < 0) {
            throw new InvalidArgumentException(sprintf(
                'tree(): branching closure returned %d at depth %d (must be >= 0).',
                $value,
                $parentDepth,
            ));
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $shape
     * @return list<array<string, mixed>>
     */
    private function normaliseExplicit(array $shape, int $depth, int $startSiblingIndex): array
    {
        $out = [];
        foreach ($shape as $i => $entry) {
            $children = [];
            $attrs = $entry;
            if (array_key_exists(self::CHILDREN_KEY, $attrs)) {
                $raw = $attrs[self::CHILDREN_KEY];
                unset($attrs[self::CHILDREN_KEY]);
                if (! is_array($raw)) {
                    throw new InvalidArgumentException(sprintf(
                        'treeFromShape: "%s" must be an array of further nodes, got %s.',
                        self::CHILDREN_KEY,
                        get_debug_type($raw),
                    ));
                }
                /** @var list<array<string, mixed>> $raw */
                $raw = array_values($raw);
                $children = $this->normaliseExplicit($raw, $depth + 1, 0);
            }

            $attrs[self::CHILDREN_KEY] = $children;
            $attrs['__meta'] = ['depth' => $depth, 'siblingIndex' => $startSiblingIndex + $i];

            $out[] = $attrs;
        }

        return $out;
    }
}
