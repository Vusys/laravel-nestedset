<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\MisconfiguredNodeException;
use Vusys\NestedSet\Tests\Fixtures\Models\BareNodeWithoutContract;
use Vusys\NestedSet\Tests\TestCase;

/**
 * A model that composes NodeTrait but forgets `implements
 * MaintainsTreeAggregates` used to silently corrupt: the `saving`
 * listener gates on that interface, so `callPendingAction()` never ran,
 * `saveAsRoot()` / `appendToNode()->save()` placed nothing, and the
 * INSERT landed the row with lft = rgt = 0 — no error, no bounds.
 * `bulkInsertTree()` masked it (it sets the bounds attributes directly).
 * The listener now throws {@see MisconfiguredNodeException} instead.
 *
 * The broken model lives in {@see BareNodeWithoutContract} (excluded from
 * PHPStan so the trait's `@phpstan-require-implements` constraint — which
 * catches the same mistake statically — doesn't flag the deliberately
 * misconfigured fixture). Saving through the real `Model::save()` method
 * exercises the exact `saving` listener the reported `saveAsRoot()` path
 * routes through.
 */
final class MissingContractGuardTest extends TestCase
{
    protected bool $allowBrokenTreeAtTearDown = true;

    private function bareNodeWithoutContract(): BareNodeWithoutContract
    {
        return new BareNodeWithoutContract(['name' => 'Root']);
    }

    #[Test]
    public function saving_a_model_without_the_contract_throws(): void
    {
        $node = $this->bareNodeWithoutContract();

        $this->expectException(MisconfiguredNodeException::class);
        $this->expectExceptionMessage('does not implement');

        $node->save();
    }

    #[Test]
    public function no_row_is_written_when_the_contract_is_missing(): void
    {
        $node = $this->bareNodeWithoutContract();

        try {
            $node->save();
        } catch (MisconfiguredNodeException) {
            // expected — the guard fires before any INSERT.
        }

        $this->assertSame(0, DB::table('categories')->count());
    }
}
