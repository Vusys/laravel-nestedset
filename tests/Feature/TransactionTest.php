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
}
