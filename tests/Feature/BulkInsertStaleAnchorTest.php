<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * bulkInsertTree opened the gap at the anchor's in-memory rgt/depth.
 * Unlike actAppendTo, it took no fresh read and no row lock, so a
 * stale-high anchor (its real bounds shrank left after a sibling before
 * it was deleted) landed the inserted subtree entirely outside the
 * recorded parent. Read + lock the anchor row inside the transaction.
 */
final class BulkInsertStaleAnchorTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function bulk_insert_uses_the_anchors_fresh_bounds(): void
    {
        $root = new Branch(['name' => 'Root', 'tickets' => 0, 'active' => true]);
        $root->saveAsRoot();

        $filler = new Branch(['name' => 'Filler', 'tickets' => 0, 'active' => true]);
        $filler->appendToNode($root->refresh())->save();

        $anchor = new Branch(['name' => 'Anchor', 'tickets' => 0, 'active' => true]);
        $anchor->appendToNode($root->refresh())->save();

        // Stale handle: in-memory bounds are (lft 4, rgt 5).
        $staleAnchor = Branch::query()->whereKey($anchor->getKey())->firstOrFail();

        // Hard-delete the sibling before the anchor — closeGap shifts the
        // anchor left to (lft 2, rgt 3), so the stale handle's rgt is now
        // two slots too high.
        Branch::query()->whereKey($filler->getKey())->firstOrFail()->delete();

        Branch::bulkInsertTree([
            ['name' => 'B', 'children' => [
                ['name' => 'B1'],
            ]],
        ], $staleAnchor);

        $b = Branch::query()->where('name', 'B')->firstOrFail();
        $b1 = Branch::query()->where('name', 'B1')->firstOrFail();
        $anchorFresh = Branch::query()->whereKey($anchor->getKey())->firstOrFail();

        // Bounds — not just parent_id — must place B inside the anchor.
        $this->assertIsDescendantOf($b, $anchorFresh);
        $this->assertIsDescendantOf($b1, $anchorFresh);
        $this->assertIsChildOf($b, $anchorFresh);
        $this->assertIsChildOf($b1, $b);
    }
}
