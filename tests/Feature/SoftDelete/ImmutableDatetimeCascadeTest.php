<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\ImmutableSoftNode;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The soft-delete cascade must work with the `immutable_datetime` cast,
 * whose stored value is a CarbonImmutable (not Illuminate's Carbon). The
 * stringifier broadened to DateTimeInterface; otherwise the cascade
 * no-opped and descendants stayed live.
 */
final class ImmutableDatetimeCascadeTest extends TestCase
{
    #[Test]
    public function soft_delete_cascades_with_immutable_datetime_cast(): void
    {
        $root = new ImmutableSoftNode(['name' => 'Root']);
        $root->makeRoot()->save();

        $child = new ImmutableSoftNode(['name' => 'Child']);
        $child->appendToNode($root->refresh())->save();

        $grand = new ImmutableSoftNode(['name' => 'Grand']);
        $grand->appendToNode($child->refresh())->save();

        $root->refresh()->delete();

        $this->assertNotNull(ImmutableSoftNode::withTrashed()->findOrFail($child->id)->deleted_at, 'child must be cascaded');
        $this->assertNotNull(ImmutableSoftNode::withTrashed()->findOrFail($grand->id)->deleted_at, 'grandchild must be cascaded');
        $this->assertSame(0, ImmutableSoftNode::query()->count(), 'no live rows should remain');
    }

    #[Test]
    public function restore_cascade_matches_stamp_with_immutable_datetime_cast(): void
    {
        $root = new ImmutableSoftNode(['name' => 'Root']);
        $root->makeRoot()->save();

        $child = new ImmutableSoftNode(['name' => 'Child']);
        $child->appendToNode($root->refresh())->save();

        $root->refresh()->delete();
        ImmutableSoftNode::withTrashed()->findOrFail($root->id)->restore();

        $this->assertSame(2, ImmutableSoftNode::query()->count(), 'both rows should be restored');
    }
}
