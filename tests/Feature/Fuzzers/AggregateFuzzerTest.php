<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Fuzzers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\Fixtures\Models\CustomColumnsBranch;
use Vusys\NestedSet\Tests\Fixtures\Models\TypedArea;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Random sequences of append / mutate / move / forceDelete-leaf
 * against aggregate-bearing models that DO NOT use SoftDeletes. The
 * soft-delete fuzzers (SoftBranch, SoftDeleteCascade) cover the
 * lifecycle branches that depend on `deleted_at`; this one covers the
 * plain hot path — the most common production shape — for:
 *
 *   - Area              inclusive SUM/COUNT/AVG/MIN/MAX
 *   - Branch            inclusive SUM, exclusive SUM/COUNT/MAX, raw-filter SUM
 *   - TypedArea         equality-filtered aggregates + filterNotNull
 *   - CustomColumnsBranch  same shape as Branch but with renamed
 *                          tree_lft / tree_rgt / tree_depth /
 *                          tree_parent_id structural columns
 *
 * Every step asserts:
 *   - `aggregatesAreBroken()` is false.
 *   - Per-row stored aggregate values equal `freshAggregate()` over
 *     the live set.
 *   - The tree structure remains valid.
 *
 * One method per fixture so each call site can be typed concretely.
 */
#[Group('fuzzer')]
final class AggregateFuzzerTest extends TestCase
{
    /**
     * @return iterable<string, array{seed: int, steps: int}>
     */
    public static function seedProvider(): iterable
    {
        $seeds = FuzzerConfig::seeds([1, 42, 1337, 9999, 314159]);
        $steps = FuzzerConfig::steps(40);

        foreach ($seeds as $seed) {
            yield "seed {$seed}, {$steps} steps" => ['seed' => $seed, 'steps' => $steps];
        }
    }

    // ================================================================
    // Area
    // ================================================================

    #[DataProvider('seedProvider')]
    public function test_area_random_walk(int $seed, int $steps): void
    {
        mt_srand($seed);

        $root = new Area($this->areaAttrs('root'));
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $parent = $this->randomArea();
            if (! $parent instanceof Area) {
                continue;
            }
            $node = new Area($this->areaAttrs("p{$i}"));
            $node->appendToNode($parent)->save();
        }

        $tag = "[Area seed={$seed}]";
        $this->assertAreaInvariants("{$tag} seed");

        $history = [];
        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99));
            $this->areaStep($action, $step);
            $history[] = "{$step}:{$action}";
            $this->assertAreaInvariants("{$tag} step {$step} ({$action})\nHistory: ".implode(' ', $history));
        }
    }

    private function areaStep(string $action, int $step): void
    {
        switch ($action) {
            case 'append':
                $parent = $this->randomArea();
                if (! $parent instanceof Area) {
                    return;
                }
                $node = new Area($this->areaAttrs("s{$step}"));
                $node->appendToNode($parent)->save();

                return;

            case 'mutate_source':
                $target = $this->randomArea();
                if (! $target instanceof Area) {
                    return;
                }
                $target->tickets = mt_rand(0, 50);
                $target->save();

                return;

            case 'move':
                /** @var list<Area> $live */
                $live = Area::query()->orderBy('lft')->get()->all();
                $leaves = array_values(array_filter(
                    $live,
                    fn (Area $m): bool => $m->parent_id !== null && ($m->rgt - $m->lft) === 1,
                ));
                if ($leaves === []) {
                    return;
                }
                $node = $leaves[mt_rand(0, count($leaves) - 1)];
                $targets = array_values(array_filter(
                    $live,
                    fn (Area $t): bool => $t->getKey() !== $node->getKey()
                        && $t->getKey() !== $node->parent_id
                        && ! $node->isAncestorOf($t),
                ));
                if ($targets === []) {
                    return;
                }
                $node->appendToNode($targets[mt_rand(0, count($targets) - 1)]->refresh())->save();

                return;

            case 'force_delete_leaf':
                /** @var list<Area> $live */
                $live = Area::query()->orderBy('lft')->get()->all();
                $leaves = array_values(array_filter(
                    $live,
                    fn (Area $m): bool => $m->parent_id !== null && ($m->rgt - $m->lft) === 1,
                ));
                if ($leaves === []) {
                    return;
                }
                $leaves[mt_rand(0, count($leaves) - 1)]->forceDelete();

                return;
        }
    }

    private function randomArea(): ?Area
    {
        /** @var list<Area> $rows */
        $rows = Area::query()->orderBy('lft')->get()->all();
        if ($rows === []) {
            return null;
        }

        return $rows[mt_rand(0, count($rows) - 1)];
    }

    private function assertAreaInvariants(string $stage): void
    {
        $this->assertFalse(
            Area::aggregatesAreBroken(),
            "{$stage} aggregates broken: ".json_encode(Area::aggregateErrors()),
        );
        $this->assertFalse(Area::isBroken(), "{$stage} tree broken");

        foreach (Area::query()->orderBy('lft')->get() as $row) {
            foreach ($this->userFacingColumns(Area::class) as $col) {
                $this->assertAggregateMatchesFresh($row, $col, $stage);
            }
        }
    }

    /** @return array<string, mixed> */
    private function areaAttrs(string $name): array
    {
        return ['name' => $name, 'tickets' => mt_rand(0, 50)];
    }

    // ================================================================
    // Branch
    // ================================================================

    #[DataProvider('seedProvider')]
    public function test_branch_random_walk(int $seed, int $steps): void
    {
        mt_srand($seed);

        $root = new Branch($this->branchAttrs('root'));
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $parent = $this->randomBranch();
            if (! $parent instanceof Branch) {
                continue;
            }
            $node = new Branch($this->branchAttrs("p{$i}"));
            $node->appendToNode($parent)->save();
        }

        $tag = "[Branch seed={$seed}]";
        $this->assertBranchInvariants("{$tag} seed");

        $history = [];
        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99));
            $this->branchStep($action, $step);
            $history[] = "{$step}:{$action}";
            $this->assertBranchInvariants("{$tag} step {$step} ({$action})\nHistory: ".implode(' ', $history));
        }
    }

    private function branchStep(string $action, int $step): void
    {
        switch ($action) {
            case 'append':
                $parent = $this->randomBranch();
                if (! $parent instanceof Branch) {
                    return;
                }
                $node = new Branch($this->branchAttrs("s{$step}"));
                $node->appendToNode($parent)->save();

                return;

            case 'mutate_source':
                $target = $this->randomBranch();
                if (! $target instanceof Branch) {
                    return;
                }
                $target->tickets = mt_rand(0, 50);
                $target->active = mt_rand(0, 1);
                $target->save();

                return;

            case 'move':
                /** @var list<Branch> $live */
                $live = Branch::query()->orderBy('lft')->get()->all();
                $leaves = array_values(array_filter(
                    $live,
                    fn (Branch $m): bool => $m->parent_id !== null && ($m->rgt - $m->lft) === 1,
                ));
                if ($leaves === []) {
                    return;
                }
                $node = $leaves[mt_rand(0, count($leaves) - 1)];
                $targets = array_values(array_filter(
                    $live,
                    fn (Branch $t): bool => $t->getKey() !== $node->getKey()
                        && $t->getKey() !== $node->parent_id
                        && ! $node->isAncestorOf($t),
                ));
                if ($targets === []) {
                    return;
                }
                $node->appendToNode($targets[mt_rand(0, count($targets) - 1)]->refresh())->save();

                return;

            case 'force_delete_leaf':
                /** @var list<Branch> $live */
                $live = Branch::query()->orderBy('lft')->get()->all();
                $leaves = array_values(array_filter(
                    $live,
                    fn (Branch $m): bool => $m->parent_id !== null && ($m->rgt - $m->lft) === 1,
                ));
                if ($leaves === []) {
                    return;
                }
                $leaves[mt_rand(0, count($leaves) - 1)]->forceDelete();

                return;
        }
    }

    private function randomBranch(): ?Branch
    {
        /** @var list<Branch> $rows */
        $rows = Branch::query()->orderBy('lft')->get()->all();
        if ($rows === []) {
            return null;
        }

        return $rows[mt_rand(0, count($rows) - 1)];
    }

    private function assertBranchInvariants(string $stage): void
    {
        $this->assertFalse(
            Branch::aggregatesAreBroken(),
            "{$stage} aggregates broken: ".json_encode(Branch::aggregateErrors()),
        );
        $this->assertFalse(Branch::isBroken(), "{$stage} tree broken");

        foreach (Branch::query()->orderBy('lft')->get() as $row) {
            foreach ($this->userFacingColumns(Branch::class) as $col) {
                $this->assertAggregateMatchesFresh($row, $col, $stage);
            }
        }
    }

    /** @return array<string, mixed> */
    private function branchAttrs(string $name): array
    {
        return [
            'name' => $name,
            'tickets' => mt_rand(0, 50),
            'active' => mt_rand(0, 1),
        ];
    }

    // ================================================================
    // TypedArea
    // ================================================================

    #[DataProvider('seedProvider')]
    public function test_typed_area_random_walk(int $seed, int $steps): void
    {
        mt_srand($seed);

        $root = new TypedArea($this->typedAreaAttrs('root'));
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $parent = $this->randomTypedArea();
            if (! $parent instanceof TypedArea) {
                continue;
            }
            $node = new TypedArea($this->typedAreaAttrs("p{$i}"));
            $node->appendToNode($parent)->save();
        }

        $tag = "[TypedArea seed={$seed}]";
        $this->assertTypedAreaInvariants("{$tag} seed");

        $history = [];
        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99));
            $this->typedAreaStep($action, $step);
            $history[] = "{$step}:{$action}";
            $this->assertTypedAreaInvariants("{$tag} step {$step} ({$action})\nHistory: ".implode(' ', $history));
        }
    }

    private function typedAreaStep(string $action, int $step): void
    {
        switch ($action) {
            case 'append':
                $parent = $this->randomTypedArea();
                if (! $parent instanceof TypedArea) {
                    return;
                }
                $node = new TypedArea($this->typedAreaAttrs("s{$step}"));
                $node->appendToNode($parent)->save();

                return;

            case 'mutate_source':
                $target = $this->randomTypedArea();
                if (! $target instanceof TypedArea) {
                    return;
                }
                $target->tickets = mt_rand(0, 50);
                $target->type = ['fire', 'water', 'earth'][mt_rand(0, 2)];
                $target->save();

                return;

            case 'move':
                /** @var list<TypedArea> $live */
                $live = TypedArea::query()->orderBy('lft')->get()->all();
                $leaves = array_values(array_filter(
                    $live,
                    fn (TypedArea $m): bool => $m->parent_id !== null && ($m->rgt - $m->lft) === 1,
                ));
                if ($leaves === []) {
                    return;
                }
                $node = $leaves[mt_rand(0, count($leaves) - 1)];
                $targets = array_values(array_filter(
                    $live,
                    fn (TypedArea $t): bool => $t->getKey() !== $node->getKey()
                        && $t->getKey() !== $node->parent_id
                        && ! $node->isAncestorOf($t),
                ));
                if ($targets === []) {
                    return;
                }
                $node->appendToNode($targets[mt_rand(0, count($targets) - 1)]->refresh())->save();

                return;

            case 'force_delete_leaf':
                /** @var list<TypedArea> $live */
                $live = TypedArea::query()->orderBy('lft')->get()->all();
                $leaves = array_values(array_filter(
                    $live,
                    fn (TypedArea $m): bool => $m->parent_id !== null && ($m->rgt - $m->lft) === 1,
                ));
                if ($leaves === []) {
                    return;
                }
                $leaves[mt_rand(0, count($leaves) - 1)]->forceDelete();

                return;
        }
    }

    private function randomTypedArea(): ?TypedArea
    {
        /** @var list<TypedArea> $rows */
        $rows = TypedArea::query()->orderBy('lft')->get()->all();
        if ($rows === []) {
            return null;
        }

        return $rows[mt_rand(0, count($rows) - 1)];
    }

    private function assertTypedAreaInvariants(string $stage): void
    {
        $this->assertFalse(
            TypedArea::aggregatesAreBroken(),
            "{$stage} aggregates broken: ".json_encode(TypedArea::aggregateErrors()),
        );
        $this->assertFalse(TypedArea::isBroken(), "{$stage} tree broken");

        foreach (TypedArea::query()->orderBy('lft')->get() as $row) {
            foreach ($this->userFacingColumns(TypedArea::class) as $col) {
                $this->assertAggregateMatchesFresh($row, $col, $stage);
            }
        }
    }

    /** @return array<string, mixed> */
    private function typedAreaAttrs(string $name): array
    {
        return [
            'name' => $name,
            'tickets' => mt_rand(0, 50),
            'type' => ['fire', 'water', 'earth'][mt_rand(0, 2)],
        ];
    }

    // ================================================================
    // CustomColumnsBranch (renamed structural columns)
    // ================================================================

    #[DataProvider('seedProvider')]
    public function test_custom_columns_branch_random_walk(int $seed, int $steps): void
    {
        mt_srand($seed);

        $root = new CustomColumnsBranch($this->branchAttrs('root'));
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $parent = $this->randomCustomColumnsBranch();
            if (! $parent instanceof CustomColumnsBranch) {
                continue;
            }
            $node = new CustomColumnsBranch($this->branchAttrs("p{$i}"));
            $node->appendToNode($parent)->save();
        }

        $tag = "[CustomColumnsBranch seed={$seed}]";
        $this->assertCustomColumnsBranchInvariants("{$tag} seed");

        $history = [];
        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99));
            $this->customColumnsBranchStep($action, $step);
            $history[] = "{$step}:{$action}";
            $this->assertCustomColumnsBranchInvariants(
                "{$tag} step {$step} ({$action})\nHistory: ".implode(' ', $history),
            );
        }
    }

    private function customColumnsBranchStep(string $action, int $step): void
    {
        switch ($action) {
            case 'append':
                $parent = $this->randomCustomColumnsBranch();
                if (! $parent instanceof CustomColumnsBranch) {
                    return;
                }
                $node = new CustomColumnsBranch($this->branchAttrs("s{$step}"));
                $node->appendToNode($parent)->save();

                return;

            case 'mutate_source':
                $target = $this->randomCustomColumnsBranch();
                if (! $target instanceof CustomColumnsBranch) {
                    return;
                }
                $target->tickets = mt_rand(0, 50);
                $target->active = mt_rand(0, 1);
                $target->save();

                return;

            case 'move':
                /** @var list<CustomColumnsBranch> $live */
                $live = CustomColumnsBranch::query()->orderBy('tree_lft')->get()->all();
                $leaves = array_values(array_filter(
                    $live,
                    fn (CustomColumnsBranch $m): bool => $m->tree_parent_id !== null && ($m->tree_rgt - $m->tree_lft) === 1,
                ));
                if ($leaves === []) {
                    return;
                }
                $node = $leaves[mt_rand(0, count($leaves) - 1)];
                $targets = array_values(array_filter(
                    $live,
                    fn (CustomColumnsBranch $t): bool => $t->getKey() !== $node->getKey()
                        && $t->getKey() !== $node->tree_parent_id
                        && ! $node->isAncestorOf($t),
                ));
                if ($targets === []) {
                    return;
                }
                $node->appendToNode($targets[mt_rand(0, count($targets) - 1)]->refresh())->save();

                return;

            case 'force_delete_leaf':
                /** @var list<CustomColumnsBranch> $live */
                $live = CustomColumnsBranch::query()->orderBy('tree_lft')->get()->all();
                $leaves = array_values(array_filter(
                    $live,
                    fn (CustomColumnsBranch $m): bool => $m->tree_parent_id !== null && ($m->tree_rgt - $m->tree_lft) === 1,
                ));
                if ($leaves === []) {
                    return;
                }
                $leaves[mt_rand(0, count($leaves) - 1)]->forceDelete();

                return;
        }
    }

    private function randomCustomColumnsBranch(): ?CustomColumnsBranch
    {
        /** @var list<CustomColumnsBranch> $rows */
        $rows = CustomColumnsBranch::query()->orderBy('tree_lft')->get()->all();
        if ($rows === []) {
            return null;
        }

        return $rows[mt_rand(0, count($rows) - 1)];
    }

    private function assertCustomColumnsBranchInvariants(string $stage): void
    {
        $this->assertFalse(
            CustomColumnsBranch::aggregatesAreBroken(),
            "{$stage} aggregates broken: ".json_encode(CustomColumnsBranch::aggregateErrors()),
        );
        $this->assertFalse(CustomColumnsBranch::isBroken(), "{$stage} tree broken");

        foreach (CustomColumnsBranch::query()->orderBy('tree_lft')->get() as $row) {
            foreach ($this->userFacingColumns(CustomColumnsBranch::class) as $col) {
                $this->assertAggregateMatchesFresh($row, $col, $stage);
            }
        }
    }

    // ================================================================
    // Shared helpers
    // ================================================================

    private function pickAction(int $roll): string
    {
        if ($roll < 25) {
            return 'append';
        }
        if ($roll < 50) {
            return 'mutate_source';
        }
        if ($roll < 70) {
            return 'move';
        }
        if ($roll < 90) {
            return 'force_delete_leaf';
        }

        return 'noop';
    }

    /**
     * Returns the user-facing aggregate columns declared on $model.
     * Internal AVG companions are excluded — their drift is implied
     * by drift on the AVG display column.
     *
     * @param  class-string<Area|Branch|TypedArea|CustomColumnsBranch>  $model
     * @return list<string>
     */
    private function userFacingColumns(string $model): array
    {
        $cols = [];
        foreach (AggregateRegistry::for($model) as $definition) {
            if ($definition instanceof AggregateDefinition && ! $definition->isInternal()) {
                $cols[] = $definition->column;
            }
        }

        return $cols;
    }

    private function assertAggregateMatchesFresh(Area|Branch|TypedArea|CustomColumnsBranch $row, string $col, string $stage): void
    {
        $stored = $row->getAttribute($col);
        $fresh = $row->freshAggregate($col);
        $name = $row->getAttribute('name');
        $nameStr = is_string($name) ? $name : '?';
        $key = $row->getKey();
        $keyStr = is_scalar($key) ? (string) $key : '?';

        $this->assertSame(
            $this->normalise($fresh),
            $this->normalise($stored),
            "{$stage} #{$keyStr} ({$nameStr}): {$col} mismatch — stored=".json_encode($stored).' fresh='.json_encode($fresh),
        );
    }

    private function normalise(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_numeric($value)) {
            return number_format((float) $value, 4, '.', '');
        }

        $this->fail('unexpected aggregate value type: '.get_debug_type($value));
    }
}
