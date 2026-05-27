<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\BitwiseArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * M2: bitwise rollups stay correct as nodes are created, source
 * columns updated, and nodes deleted.
 *
 * - bitOr is delta-maintainable on insert (`parent |= new_value`);
 *   delete forces a chain recompute because a lost bit can't be
 *   undone from the rolled-up value alone.
 * - bitXor is delta-maintainable end-to-end — XOR is self-inverse, so
 *   `parent ^= new` adds a contribution and `parent ^= old` removes
 *   one.
 * - bitAnd routes through chain recompute on every mutation that
 *   touches the source column.
 */
final class BitwiseMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    // ----------------------------------------------------------------
    // Insert path
    // ----------------------------------------------------------------

    public function test_root_inclusive_bitwise_equals_self_value_on_insert(): void
    {
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b1010]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertSame(0b1010, $this->asInt($root->features_or));
        $this->assertSame(0b1010, $this->asInt($root->features_and));
        $this->assertSame(0b1010, $this->asInt($root->features_xor));
    }

    public function test_bitwise_for_motivating_tree(): void
    {
        // Root(0001) > A(0010) > A1(0100); Root > B(1000).
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'A', 'feature_bits' => 0b0010]);
        $a->appendToNode($root)->save();

        $a1 = new BitwiseArea(['name' => 'A1', 'feature_bits' => 0b0100]);
        $a1->appendToNode($a->refresh())->save();

        $b = new BitwiseArea(['name' => 'B', 'feature_bits' => 0b1000]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $a->refresh();
        $a1->refresh();
        $b->refresh();

        // OR over the subtree:
        //   Root = 0001 | 0010 | 0100 | 1000 = 1111
        //   A    = 0010 | 0100                = 0110
        //   A1   = 0100                        = 0100
        //   B    = 1000                        = 1000
        $this->assertSame(0b1111, $this->asInt($root->features_or));
        $this->assertSame(0b0110, $this->asInt($a->features_or));
        $this->assertSame(0b0100, $this->asInt($a1->features_or));
        $this->assertSame(0b1000, $this->asInt($b->features_or));

        // AND over the subtree:
        //   Root = 0001 & 0010 & 0100 & 1000 = 0000
        //   A    = 0010 & 0100                = 0000
        //   A1   = 0100                        = 0100
        //   B    = 1000                        = 1000
        $this->assertSame(0b0000, $this->asInt($root->features_and));
        $this->assertSame(0b0000, $this->asInt($a->features_and));
        $this->assertSame(0b0100, $this->asInt($a1->features_and));
        $this->assertSame(0b1000, $this->asInt($b->features_and));

        // XOR over the subtree:
        //   Root = 0001 ^ 0010 ^ 0100 ^ 1000 = 1111
        //   A    = 0010 ^ 0100                = 0110
        //   A1   = 0100                        = 0100
        //   B    = 1000                        = 1000
        $this->assertSame(0b1111, $this->asInt($root->features_xor));
        $this->assertSame(0b0110, $this->asInt($a->features_xor));
        $this->assertSame(0b0100, $this->asInt($a1->features_xor));
        $this->assertSame(0b1000, $this->asInt($b->features_xor));
    }

    public function test_bitwise_with_shared_bits_in_and_fold(): void
    {
        // Root(1100) > A(1110) > A1(1111). Every row has bits 2 and 3
        // set, so the AND fold preserves those at every level.
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b1100]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'A', 'feature_bits' => 0b1110]);
        $a->appendToNode($root)->save();

        $a1 = new BitwiseArea(['name' => 'A1', 'feature_bits' => 0b1111]);
        $a1->appendToNode($a->refresh())->save();

        $root->refresh();
        $a->refresh();
        $a1->refresh();

        $this->assertSame(0b1111, $this->asInt($root->features_or));
        $this->assertSame(0b1100, $this->asInt($root->features_and));
        // XOR: 1100 ^ 1110 ^ 1111 = 1101
        $this->assertSame(0b1101, $this->asInt($root->features_xor));
    }

    // ----------------------------------------------------------------
    // Source-update path (capture deltas)
    // ----------------------------------------------------------------

    public function test_source_update_propagates_through_bit_xor_delta(): void
    {
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $child = new BitwiseArea(['name' => 'Child', 'feature_bits' => 0b0010]);
        $child->appendToNode($root)->save();

        $root->refresh();
        $this->assertSame(0b0011, $this->asInt($root->features_xor));

        // Mutate child: 0010 → 0100. XOR delta = 0010 ^ 0100 = 0110.
        // Root's XOR was 0011; new value 0011 ^ 0110 = 0101.
        $child->feature_bits = 0b0100;
        $child->save();

        $root->refresh();
        $child->refresh();

        $this->assertSame(0b0101, $this->asInt($root->features_xor));
        $this->assertSame(0b0100, $this->asInt($child->features_xor));
    }

    public function test_source_update_triggers_recompute_for_bit_or_and_bit_and(): void
    {
        // Root(0001) > A(1110). After mutation A drops bit 1 (0010 cleared)
        // — Root's bitOr was 1111 but A was the only holder of bit 1.
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'A', 'feature_bits' => 0b1110]);
        $a->appendToNode($root)->save();

        $root->refresh();
        $this->assertSame(0b1111, $this->asInt($root->features_or));
        $this->assertSame(0b0000, $this->asInt($root->features_and));

        // Mutate A: 1110 → 1100. Loses bit 1, gains nothing.
        $a->feature_bits = 0b1100;
        $a->save();

        $root->refresh();
        $a->refresh();

        // bitOr recomputed: 0001 | 1100 = 1101.
        $this->assertSame(0b1101, $this->asInt($root->features_or));
        // bitAnd recomputed: 0001 & 1100 = 0000.
        $this->assertSame(0b0000, $this->asInt($root->features_and));
        // bitXor delta: 1110 ^ 1100 = 0010; root was 1111; new = 1101.
        $this->assertSame(0b1101, $this->asInt($root->features_xor));
    }

    // ----------------------------------------------------------------
    // Delete path
    // ----------------------------------------------------------------

    public function test_delete_undoes_bit_xor_contribution_via_self_inverse(): void
    {
        // Build Root(0001) > A(0010) > A1(0100). XOR root = 0111.
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'A', 'feature_bits' => 0b0010]);
        $a->appendToNode($root)->save();

        $a1 = new BitwiseArea(['name' => 'A1', 'feature_bits' => 0b0100]);
        $a1->appendToNode($a->refresh())->save();

        $root->refresh();
        $this->assertSame(0b0111, $this->asInt($root->features_xor));

        // Delete A1 (its subtree XOR is just 0100). Root XOR is 0111
        // ^ 0100 = 0011. Refresh first — the in-memory model's
        // `features_xor` is NULL until reloaded (DeltaMaintenance
        // updates the DB row, not the PHP object).
        $a1->refresh()->delete();

        $root->refresh();

        $this->assertSame(0b0011, $this->asInt($root->features_xor));
        $this->assertSame(0b0011, $this->asInt($root->features_or));
        $this->assertSame(0b0000, $this->asInt($root->features_and));
    }

    public function test_delete_triggers_recompute_for_bit_or_when_only_holder_disappears(): void
    {
        // Root(0001) > A(1000). A is the only holder of bit 3. Deleting
        // A should drop bit 3 from Root's bitOr.
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'A', 'feature_bits' => 0b1000]);
        $a->appendToNode($root)->save();

        $root->refresh();
        $this->assertSame(0b1001, $this->asInt($root->features_or));

        $a->refresh()->delete();
        $root->refresh();

        $this->assertSame(0b0001, $this->asInt($root->features_or));
    }

    public function test_deleting_subtree_with_internal_node_replays_bit_xor_correctly(): void
    {
        // Root > A > A1, A > A2. Delete the A node (and cascade
        // recompute via NodeTrait); Root's XOR should match a freshly
        // computed XOR of just Root.
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'A', 'feature_bits' => 0b0010]);
        $a->appendToNode($root)->save();

        $a1 = new BitwiseArea(['name' => 'A1', 'feature_bits' => 0b0100]);
        $a1->appendToNode($a->refresh())->save();

        $a2 = new BitwiseArea(['name' => 'A2', 'feature_bits' => 0b1000]);
        $a2->appendToNode($a->refresh())->save();

        $root->refresh();
        // Initial XOR: 0001 ^ 0010 ^ 0100 ^ 1000 = 1111.
        $this->assertSame(0b1111, $this->asInt($root->features_xor));

        // Delete A — A's stored subtree XOR is (0010 ^ 0100 ^ 1000) =
        // 1110. Root's new XOR = 1111 ^ 1110 = 0001.
        $a->refresh();
        $this->assertSame(0b1110, $this->asInt($a->features_xor));
        $a->delete();

        $root->refresh();
        $this->assertSame(0b0001, $this->asInt($root->features_xor));
    }

    // ----------------------------------------------------------------
    // Empty subtree
    // ----------------------------------------------------------------

    public function test_solitary_root_then_all_descendants_deleted_keeps_self_value(): void
    {
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0101]);
        $root->saveAsRoot();

        $child = new BitwiseArea(['name' => 'Child', 'feature_bits' => 0b1010]);
        $child->appendToNode($root)->save();

        $child->refresh()->delete();
        $root->refresh();

        // After deleting child the root's inclusive bitwise is just
        // its own contribution.
        $this->assertSame(0b0101, $this->asInt($root->features_or));
        $this->assertSame(0b0101, $this->asInt($root->features_and));
        $this->assertSame(0b0101, $this->asInt($root->features_xor));
    }

    // ----------------------------------------------------------------
    // fixAggregates round-trip
    // ----------------------------------------------------------------

    public function test_fix_aggregates_is_a_no_op_on_a_correctly_maintained_tree(): void
    {
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'A', 'feature_bits' => 0b0010]);
        $a->appendToNode($root)->save();

        $a1 = new BitwiseArea(['name' => 'A1', 'feature_bits' => 0b0100]);
        $a1->appendToNode($a->refresh())->save();

        $result = BitwiseArea::fixAggregates();

        $this->assertSame(0, $result->totalRowsUpdated);
    }

    public function test_fix_aggregates_repairs_drift_in_bitwise_columns(): void
    {
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'A', 'feature_bits' => 0b0010]);
        $a->appendToNode($root)->save();

        $a1 = new BitwiseArea(['name' => 'A1', 'feature_bits' => 0b0100]);
        $a1->appendToNode($a->refresh())->save();

        // Corrupt every bitwise column on the root.
        DB::table('bitwise_areas')->where('id', $root->getKey())->update([
            'features_or' => 0,
            'features_and' => 0,
            'features_xor' => 0,
        ]);

        BitwiseArea::fixAggregates();

        $root->refresh();
        $this->assertSame(0b0111, $this->asInt($root->features_or));
        $this->assertSame(0b0000, $this->asInt($root->features_and));
        $this->assertSame(0b0111, $this->asInt($root->features_xor));
    }
}
