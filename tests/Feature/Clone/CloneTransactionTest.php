<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Clone;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Atomicity guarantees: a clone called inside an outer transaction
 * either commits whole or rolls back whole. No partial subtree, no
 * leftover gap, no `fixTree` follow-up required.
 */
final class CloneTransactionTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function outer_transaction_rollback_unwinds_cloned_rows(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();

        $child = new Category(['name' => 'src_child']);
        $child->appendToNode($source)->save();
        $source->refresh();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $countBefore = Category::query()->count();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback');
        try {
            DB::transaction(function () use ($source, $destination): never {
                $source->cloneSubtreeTo($destination);
                throw new \RuntimeException('rollback');
            });
        } finally {
            $this->assertSame($countBefore, Category::query()->count(), 'clone rolled back with outer transaction');
            $this->assertTreeIsIntact(Category::class);
        }
    }
}
