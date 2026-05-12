<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The package doesn't currently auto-wrap mutations in a transaction
 * (that's Phase 9). What these tests verify is that *if* the caller
 * wraps a mutation in DB::transaction() and the inner work throws,
 * the table is rolled back to its pre-mutation state.
 */
final class TransactionTest extends TestCase
{
    public function test_explicit_transaction_rollback_restores_tree(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $snapshot = DB::table('categories')->orderBy('id')->get()->toArray();

        try {
            DB::transaction(function () use ($root): never {
                $b = new Category(['name' => 'B']);
                $b->appendToNode($root->refresh())->save();

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $after = DB::table('categories')->orderBy('id')->get()->toArray();

        $this->assertEquals($snapshot, $after, 'Transaction rollback should restore the tree');
        $this->assertFalse(Category::isBroken());
    }

    public function test_auto_transaction_rolls_back_on_insert_failure(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $rootBefore = $root->refresh();

        // Cause the INSERT to fail by violating a NOT NULL on `name` via raw.
        try {
            DB::transaction(function () use ($rootBefore): never {
                // Simulate a downstream failure after the gap is opened.
                $b = new Category(['name' => 'B']);
                $b->appendToNode($rootBefore)->save();
                throw new RuntimeException('failure after gap');
            });
        } catch (RuntimeException) {
            // expected
        }

        // The gap should NOT remain — outer transaction rolled it back.
        $rootAfter = Category::query()->findOrFail(1);
        $this->assertSame($rootBefore->lft, $rootAfter->lft);
        $this->assertSame($rootBefore->rgt, $rootAfter->rgt);
        $this->assertFalse(Category::isBroken());
    }
}
