<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedPathNode;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The subtree path-rewrite UPDATE (emitSubtreeRewrite) has two edges that
 * single-column slug fixtures can't reach:
 *
 *  1. Multi-column scope: the WHERE's scope placeholders must bind to the
 *     scope VALUES in the same order — a reversed order silently rewrites
 *     the wrong tenant's rows (or none).
 *  2. Multibyte prefixes: the SUBSTRING start offset must be a character
 *     count, not a byte count, or descendant paths are cut mid-rune.
 *
 * Uses {@see ScopedPathNode} — two-column scope, raw `attribute:` source.
 */
final class ScopedMultibytePathRewriteTest extends TestCase
{
    private function node(string $name, int $tenant, int $menu, ?ScopedPathNode $parent = null): ScopedPathNode
    {
        $node = new ScopedPathNode(['name' => $name, 'tenant_id' => $tenant, 'menu_id' => $menu]);

        if (! $parent instanceof ScopedPathNode) {
            $node->saveAsRoot();
        } else {
            $node->appendToNode($parent->refresh())->save();
        }

        return $node;
    }

    #[Test]
    public function move_rewrites_descendant_paths_under_a_two_column_scope(): void
    {
        // Tenant (1,10): Root > A > A1 ; and a sibling B.
        $root = $this->node('root', 1, 10);
        $a = $this->node('a', 1, 10, $root);
        $a1 = $this->node('a1', 1, 10, $a);
        $b = $this->node('b', 1, 10, $root);

        // A different scope with a node that must never be touched.
        $other = $this->node('other-root', 2, 20);

        // Move A (with A1) under B. The descendant rewrite must run inside
        // the (1,10) scope and rewrite A1's path prefix correctly.
        $a->refresh()->appendToNode(ScopedPathNode::query()->whereKey($b->getKey())->firstOrFail())->save();

        $this->assertSame('/root/b/a/', ScopedPathNode::query()->whereKey($a->getKey())->firstOrFail()->path);
        $this->assertSame('/root/b/a/a1/', ScopedPathNode::query()->whereKey($a1->getKey())->firstOrFail()->path);

        // The other scope's node is untouched (its path never rewritten by
        // a mis-bound scope predicate).
        $this->assertSame('/other-root/', ScopedPathNode::query()->whereKey($other->getKey())->firstOrFail()->path);

        $this->assertFalse(ScopedPathNode::isBroken($root));
    }

    #[Test]
    public function move_rewrites_multibyte_descendant_paths_without_cutting_runes(): void
    {
        // Multibyte segments (each accented/CJK char is multiple UTF-8
        // bytes, so a byte-indexed SUBSTRING offset would slice wrong).
        $root = $this->node('café', 1, 10);
        $parent = $this->node('naïve', 1, 10, $root);
        $child = $this->node('über', 1, 10, $parent);
        $target = $this->node('東京', 1, 10, $root);

        $this->assertSame('/café/naïve/über/', ScopedPathNode::query()->whereKey($child->getKey())->firstOrFail()->path);

        // Move `naïve` (whose prefix `/café/naïve/` is multibyte) under 東京.
        $parent->refresh()->appendToNode(ScopedPathNode::query()->whereKey($target->getKey())->firstOrFail())->save();

        $this->assertSame('/café/東京/naïve/', ScopedPathNode::query()->whereKey($parent->getKey())->firstOrFail()->path);
        $this->assertSame(
            '/café/東京/naïve/über/',
            ScopedPathNode::query()->whereKey($child->getKey())->firstOrFail()->path,
            'multibyte descendant path was cut at the wrong offset',
        );
    }
}
